<?php

namespace App\Api\Notes\Services;

use App\Api\Notes\Models\Note;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Collection;

class NoteService
{
    private const string CACHE_KEY = 'notes';
    private const string CACHE_KEY_NEWEST = 'notes_newest';
    private const int CACHE_TTL = 86400;

    public function __construct(private readonly UserCacheService $cache) {}

    public function list(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            fn() => Note::forUser($user->id)->with(['tags'])->orderBy('title', 'asc')->get()
        );
    }

    public function newest(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY_NEWEST, $user->id],
            self::CACHE_TTL,
            fn() => Note::forUser($user->id)->orderBy('created_at', 'desc')->limit(5)->get()
        );
    }

    public function find(Note $note): Note
    {
        return $note;
    }

    public function create(User $user, array $data): Note
    {
        $data['user_id'] = $user->id;
        $note = Note::create($data);

        if (isset($data['tags'])) {
            $note->tags()->attach($data['tags']);
        }

        $note->load(['user', 'category', 'tags']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);
        $this->cache->forget([self::CACHE_KEY_NEWEST, $user->id]);

        return $note;
    }

    public function update(Note $note, array $data): Note
    {
        $note->update($data);

        if (array_key_exists('tags', $data)) {
            $note->tags()->sync($data['tags']);
        }

        $note->load(['user', 'category', 'tags']);

        $this->cache->forget([self::CACHE_KEY, $note->user_id]);
        $this->cache->forget([self::CACHE_KEY_NEWEST, $note->user_id]);

        return $note;
    }

    public function destroy(Note $note): void
    {
        $userId = $note->user_id;
        $note->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
        $this->cache->forget([self::CACHE_KEY_NEWEST, $userId]);
    }
}
