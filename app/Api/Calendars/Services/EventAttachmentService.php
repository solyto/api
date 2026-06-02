<?php

namespace App\Api\Calendars\Services;

use App\Api\Calendars\Models\CalendarEventNote;
use App\Api\Calendars\Models\CalendarEventTodo;
use App\Api\Notes\Models\Note;
use App\Api\Todos\Models\Todo;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Collection;

class EventAttachmentService
{
    private const string CACHE_KEY_TODOS = 'event_attachment_todos';
    private const string CACHE_KEY_NOTES = 'event_attachment_notes';
    private const int CACHE_TTL = 60;

    public function __construct(private readonly UserCacheService $cache) {}

    public function getTodoAttachments(User $user, int $eventId): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY_TODOS, $user->id, $eventId],
            self::CACHE_TTL,
            function () use ($user, $eventId) {
                $todoIds = CalendarEventTodo::forUser($user->id)
                    ->forEvent($eventId)
                    ->pluck('todo_id');

                return Todo::whereIn('id', $todoIds)
                    ->with(['category', 'tags', 'subtasks'])
                    ->get();
            }
        );
    }

    public function getNoteAttachments(User $user, int $eventId): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY_NOTES, $user->id, $eventId],
            self::CACHE_TTL,
            function () use ($user, $eventId) {
                $noteIds = CalendarEventNote::forUser($user->id)
                    ->forEvent($eventId)
                    ->pluck('note_id');

                return Note::whereIn('id', $noteIds)->get();
            }
        );
    }

    public function attachTodo(User $user, int $eventId, Todo $todo): bool
    {
        $exists = CalendarEventTodo::forUser($user->id)
            ->forEvent($eventId)
            ->where('todo_id', $todo->id)
            ->exists();

        if ($exists) {
            return false;
        }

        CalendarEventTodo::create([
            'calendar_object_id' => $eventId,
            'todo_id' => $todo->id,
            'user_id' => $user->id,
        ]);

        $this->cache->forget([self::CACHE_KEY_TODOS, $user->id, $eventId]);

        return true;
    }

    public function detachTodo(User $user, int $eventId, string $todoId): void
    {
        CalendarEventTodo::forUser($user->id)
            ->forEvent($eventId)
            ->where('todo_id', $todoId)
            ->delete();

        $this->cache->forget([self::CACHE_KEY_TODOS, $user->id, $eventId]);
    }

    public function attachNote(User $user, int $eventId, Note $note): bool
    {
        $exists = CalendarEventNote::forUser($user->id)
            ->forEvent($eventId)
            ->where('note_id', $note->id)
            ->exists();

        if ($exists) {
            return false;
        }

        CalendarEventNote::create([
            'calendar_object_id' => $eventId,
            'note_id' => $note->id,
            'user_id' => $user->id,
        ]);

        $this->cache->forget([self::CACHE_KEY_NOTES, $user->id, $eventId]);

        return true;
    }

    public function detachNote(User $user, int $eventId, string $noteId): void
    {
        CalendarEventNote::forUser($user->id)
            ->forEvent($eventId)
            ->where('note_id', $noteId)
            ->delete();

        $this->cache->forget([self::CACHE_KEY_NOTES, $user->id, $eventId]);
    }

    public function deleteAllForEvent(User $user, int $eventId): void
    {
        CalendarEventTodo::forEvent($eventId)->delete();
        CalendarEventNote::forEvent($eventId)->delete();
        $this->cache->forget([self::CACHE_KEY_TODOS, $user->id, $eventId]);
        $this->cache->forget([self::CACHE_KEY_NOTES, $user->id, $eventId]);
    }
}
