<?php

namespace App\Api\Notes\Services;

use App\Api\Notes\Models\Note;
use App\Api\Notes\Models\NoteCategory;
use App\Api\Notes\Resources\NoteResource;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class NoteService
{
    // _v2: lists cached before they were stored as arrays instead of models
    private const string CACHE_KEY = 'notes_v2';

    private const string CACHE_KEY_HIDDEN = 'notes_hidden_v2';

    private const string CACHE_KEY_NEWEST = 'notes_newest_v2';

    private const string CACHE_KEY_RECENT = 'notes_recent';

    private const int CACHE_TTL = 86400;

    // Matches relative and absolute links in both HTML (href) and older Markdown content, but only when the
    // uuid is the whole /notes path segment (as the app's NoteLinkService requires).
    private const string LINK_PATTERN = '~(?:^|[\s"\'<>(=\[])(?:[a-z][a-z\d+.-]*://[^/\s"\'<>]+)?/notes/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?=[\s"\'<>)\]]|/?$|[?#])~i';

    public function __construct(private readonly UserCacheService $cache) {}

    public function list(User $user): array
    {
        return $this->rememberList(
            self::CACHE_KEY,
            $user->id,
            fn () => Note::forUser($user->id)
                ->where(fn ($query) => $this->whereVisible($query, $user->id))
                ->with(['tags'])
                ->orderBy('title', 'asc')
                ->get()
        );
    }

    public function listHidden(User $user): array
    {
        return $this->rememberList(
            self::CACHE_KEY_HIDDEN,
            $user->id,
            fn () => Note::forUser($user->id)
                ->whereIn('category_id', $this->hiddenCategoryIds($user->id))
                ->with(['tags'])
                ->orderBy('title', 'asc')
                ->get()
        );
    }

    public function newest(User $user): array
    {
        return $this->rememberList(
            self::CACHE_KEY_NEWEST,
            $user->id,
            fn () => Note::forUser($user->id)
                ->where(fn ($query) => $this->whereVisible($query, $user->id))
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
        );
    }

    // Creating a note sets updated_at too, so new notes count as recently changed.
    public function recent(User $user): array
    {
        return $this->rememberList(
            self::CACHE_KEY_RECENT,
            $user->id,
            fn () => Note::forUser($user->id)
                ->where(fn ($query) => $this->whereVisible($query, $user->id))
                ->orderBy('updated_at', 'desc')
                ->limit(5)
                ->get()
        );
    }

    public function findVisible(User $user, string $id): ?Note
    {
        return Note::forUser($user->id)
            ->whereKey($id)
            ->where(fn ($query) => $this->whereVisible($query, $user->id))
            ->first();
    }

    // Hidden and system categories stay out of the plain lists with all their descendants (docs/STANDARDS.md 13.3).
    /** @return array<int, int> */
    public function hiddenCategoryIds(string $userId): array
    {
        $categories = NoteCategory::forUser($userId)->get(['id', 'parent_id', 'is_hidden', 'is_system']);
        $childIds = $categories->groupBy('parent_id')->map(fn (Collection $children) => $children->pluck('id')->all());

        $hidden = [];
        $pending = $categories->filter(fn (NoteCategory $category) => $category->is_hidden || $category->is_system)
            ->pluck('id')
            ->all();

        while ($pending !== []) {
            $id = array_pop($pending);

            if (isset($hidden[$id])) {
                continue;
            }

            $hidden[$id] = $id;
            array_push($pending, ...$childIds->get($id, []));
        }

        return array_values($hidden);
    }

    // The journal folder is found by is_system, not by title, and is read even while hidden.
    public function journalMarks(User $user, string $from, string $to): array
    {
        $journalCategoryIds = NoteCategory::forUser($user->id)
            ->where('is_system', true)
            ->pluck('id');

        if ($journalCategoryIds->isEmpty()) {
            return [];
        }

        return Note::forUser($user->id)
            ->whereIn('category_id', $journalCategoryIds)
            ->whereBetween('title', [$from, $to])
            ->orderBy('title')
            ->pluck('title')
            ->filter(fn (string $title) => Carbon::hasFormat($title, 'Y-m-d'))
            ->unique()
            ->values()
            ->all();
    }

    public function create(User $user, array $data): Note
    {
        $data['user_id'] = $user->id;
        $note = Note::create($data);

        if (isset($data['tags'])) {
            $note->tags()->attach($data['tags']);
        }

        $this->syncLinks($note);

        $note->load(['user', 'category', 'tags']);

        $this->forgetUser($user->id);

        return $note;
    }

    public function update(Note $note, array $data): Note
    {
        $note->update($data);

        if (array_key_exists('tags', $data)) {
            $note->tags()->sync($data['tags']);
        }

        if (array_key_exists('content', $data)) {
            $this->syncLinks($note);
        }

        $note->load(['user', 'category', 'tags']);

        $this->forgetUser($note->user_id);

        return $note;
    }

    public function destroy(Note $note): void
    {
        $userId = $note->user_id;
        $note->delete();

        $this->forgetUser($userId);
    }

    // Links to missing notes, other users' notes and the note itself are not stored; the content stays as written.
    public function syncLinks(Note $note): void
    {
        preg_match_all(self::LINK_PATTERN, (string) $note->content, $matches);
        $ids = array_unique(array_map('strtolower', $matches[1]));

        $targetIds = $ids === [] ? [] : Note::forUser($note->user_id)
            ->whereKey($ids)
            ->whereKeyNot($note->id)
            ->pluck('id')
            ->all();

        $note->linkedNotes()->sync($targetIds);
    }

    public function backlinks(Note $note): Collection
    {
        return $note->backlinks()
            ->where(fn ($query) => $this->whereVisible($query, $note->user_id))
            ->orderBy('notes.title')
            ->get(['notes.id', 'notes.title']);
    }

    // Public because category writes change which notes are hidden; NoteCategoryService calls it.
    public function forgetUser(string $userId): void
    {
        $this->cache->forget([self::CACHE_KEY, $userId]);
        $this->cache->forget([self::CACHE_KEY_HIDDEN, $userId]);
        $this->cache->forget([self::CACHE_KEY_NEWEST, $userId]);
        $this->cache->forget([self::CACHE_KEY_RECENT, $userId]);
    }

    private function rememberList(string $key, string $userId, callable $query): array
    {
        return $this->cache->remember([$key, $userId], self::CACHE_TTL, fn () => $this->toResponseArrays($query()));
    }

    // Cached as response arrays, not models: a serialised model holds its attributes twice (attributes and
    // original), which with the full note content doubles the cached size.
    private function toResponseArrays(Collection $notes): array
    {
        $json = json_encode(NoteResource::collection($notes), JSON_THROW_ON_ERROR);

        return json_decode($json, true);
    }

    // Uncategorised notes stay visible: SQL NOT IN never matches NULL.
    private function whereVisible(Builder $query, string $userId): void
    {
        $query->whereNull('category_id')
            ->orWhereNotIn('category_id', $this->hiddenCategoryIds($userId));
    }
}
