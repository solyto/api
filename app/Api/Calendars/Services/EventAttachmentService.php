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

    public function getTodoAttachments(User $user, string $eventId): Collection
    {
        return $this->cache->rememberTagged(
            $this->cacheTags($user),
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

    public function getNoteAttachments(User $user, string $eventId): Collection
    {
        return $this->cache->rememberTagged(
            $this->cacheTags($user),
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

    public function attachTodo(User $user, string $eventId, Todo $todo): bool
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

        $this->forgetTodos($user, $eventId);

        return true;
    }

    public function detachTodo(User $user, string $eventId, string $todoId): void
    {
        CalendarEventTodo::forUser($user->id)
            ->forEvent($eventId)
            ->where('todo_id', $todoId)
            ->delete();

        $this->forgetTodos($user, $eventId);
    }

    public function attachNote(User $user, string $eventId, Note $note): bool
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

        $this->forgetNotes($user, $eventId);

        return true;
    }

    public function detachNote(User $user, string $eventId, string $noteId): void
    {
        CalendarEventNote::forUser($user->id)
            ->forEvent($eventId)
            ->where('note_id', $noteId)
            ->delete();

        $this->forgetNotes($user, $eventId);
    }

    public function deleteAllForEvent(User $user, string $eventId): void
    {
        CalendarEventTodo::forEvent($eventId)->delete();
        CalendarEventNote::forEvent($eventId)->delete();
        $this->forgetTodos($user, $eventId);
        $this->forgetNotes($user, $eventId);
    }

    private function forgetTodos(User $user, string $eventId): void
    {
        $this->cache->forgetTagged($this->cacheTags($user), [self::CACHE_KEY_TODOS, $user->id, $eventId]);
    }

    private function forgetNotes(User $user, string $eventId): void
    {
        $this->cache->forgetTagged($this->cacheTags($user), [self::CACHE_KEY_NOTES, $user->id, $eventId]);
    }

    /** @return array<int, string> */
    private function cacheTags(User $user): array
    {
        return [UserCacheService::userTag($user->id)];
    }
}
