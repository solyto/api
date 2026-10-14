<?php

namespace App\Api\Notes\Services;

use App\Api\Notes\Models\Note;
use App\Api\Notes\Models\NoteCategory;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class NoteService
{
    private const string CACHE_KEY = 'notes';

    private const string CACHE_KEY_HIDDEN = 'notes_hidden';

    private const string CACHE_KEY_NEWEST = 'notes_newest';

    private const int CACHE_TTL = 86400;

    public function __construct(private readonly UserCacheService $cache) {}

    public function list(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            fn () => Note::forUser($user->id)
                ->where(fn ($query) => $this->whereVisible($query, $user->id))
                ->with(['tags'])
                ->orderBy('title', 'asc')
                ->get()
        );
    }

    /**
     * The notes the plain list leaves out: those in a hidden or system
     * category, or in a descendant of one.
     */
    public function listHidden(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY_HIDDEN, $user->id],
            self::CACHE_TTL,
            fn () => Note::forUser($user->id)
                ->whereIn('category_id', $this->hiddenCategoryIds($user->id))
                ->with(['tags'])
                ->orderBy('title', 'asc')
                ->get()
        );
    }

    public function newest(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY_NEWEST, $user->id],
            self::CACHE_TTL,
            fn () => Note::forUser($user->id)
                ->where(fn ($query) => $this->whereVisible($query, $user->id))
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
        );
    }

    /**
     * Ids of the user's categories that the plain lists leave out: every
     * category with is_hidden or is_system set, plus all of its descendants
     * at any depth (docs/STANDARDS.md 13.3).
     *
     * @return array<int, int>
     */
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

    public function find(Note $note): Note
    {
        return $note;
    }

    /**
     * Dates (Y-m-d) within [$from, $to] that the user has a journal entry for.
     * The journal folder is identified by is_system, not title, and is read
     * even while hidden.
     */
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

    /**
     * Category writes change which notes are hidden, so NoteCategoryService
     * forgets every note list through here.
     */
    public function forgetLists(string $userId): void
    {
        $this->forgetUser($userId);
    }

    private function forgetUser(string $userId): void
    {
        $this->cache->forget([self::CACHE_KEY, $userId]);
        $this->cache->forget([self::CACHE_KEY_HIDDEN, $userId]);
        $this->cache->forget([self::CACHE_KEY_NEWEST, $userId]);
    }

    /**
     * Uncategorised notes stay visible: SQL NOT IN never matches NULL.
     */
    private function whereVisible(Builder $query, string $userId): void
    {
        $query->whereNull('category_id')
            ->orWhereNotIn('category_id', $this->hiddenCategoryIds($userId));
    }
}
