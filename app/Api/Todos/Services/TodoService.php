<?php

namespace App\Api\Todos\Services;

use App\Api\Todos\Models\Todo;
use App\Api\Todos\Models\TodoSubtask;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TodoService
{
    private const string CACHE_KEY = 'todos';
    private const string CACHE_KEY_DUE = 'todos_due';
    private const int CACHE_TTL = 60;

    public function __construct(private readonly UserCacheService $cache) {}

    public function list(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            fn() => Todo::forUser($user->id)
                ->where(function ($q) {
                    $q->where('is_completed', 0)
                      ->orWhere(function ($q) {
                          $q->where('is_completed', 1)
                            ->where('updated_at', '>=', now()->subMinutes(5));
                      });
                })
                ->with(['category', 'tags', 'subtasks'])
                ->get()
        );
    }

    public function listDueDate(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY_DUE, $user->id],
            self::CACHE_TTL,
            fn() => Todo::forUser($user->id)
                ->where('due_date', '>=', now()->subDays(30))
                ->get()
        );
    }

    public function find(Todo $todo): Todo
    {
        $todo->load(['category', 'tags', 'subtasks']);

        return $todo;
    }

    public function create(User $user, array $data): Todo
    {
        $data['user_id'] = $user->id;
        $todo = Todo::create($data);

        if (isset($data['tags'])) {
            $todo->tags()->attach($data['tags']);
        }

        $todo->load(['user', 'category', 'tags', 'subtasks']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);
        $this->cache->forget([self::CACHE_KEY_DUE, $user->id]);

        return $todo;
    }

    public function update(Todo $todo, array $data): Todo
    {
        $completing = isset($data['is_completed']) && $data['is_completed'] === true && !$todo->is_completed;

        if ($completing) {
            $data['completed_at'] = now();
        }

        $todo->update($data);

        if ($completing && $todo->recurrence_frequency !== null && $todo->due_at !== null) {
            $this->spawnNextOccurrence($todo);
        }

        if (array_key_exists('tags', $data)) {
            $todo->tags()->sync($data['tags']);
        }

        $todo->load(['user', 'category', 'tags', 'subtasks']);

        $this->cache->forget([self::CACHE_KEY, $todo->user_id]);
        $this->cache->forget([self::CACHE_KEY_DUE, $todo->user_id]);

        return $todo;
    }

    private function spawnNextOccurrence(Todo $todo): void
    {
        $dueAt = Carbon::parse($todo->due_at);
        $interval = max(1, (int) $todo->recurrence_interval);
        $today = Carbon::today();

        do {
            $dueAt = match ($todo->recurrence_frequency) {
                'daily'   => $dueAt->addDays($interval),
                'weekly'  => $dueAt->addWeeks($interval),
                'monthly' => $dueAt->addMonthsNoOverflow($interval),
                'yearly'  => $dueAt->addYearsNoOverflow($interval),
            };
        } while ($dueAt->lte($today));

        if ($todo->recurrence_ends_at !== null && $dueAt->gt(Carbon::parse($todo->recurrence_ends_at))) {
            return;
        }

        $parentId = $todo->parent_task_id ?? $todo->id;

        $next = Todo::create([
            'title'                 => $todo->title,
            'description'           => $todo->description,
            'priority'              => $todo->priority,
            'status'                => 'pending',
            'effort'                => $todo->effort,
            'due_at'                => $dueAt->toDateString(),
            'user_id'               => $todo->user_id,
            'category_id'           => $todo->category_id,
            'recurrence_frequency'  => $todo->recurrence_frequency,
            'recurrence_interval'   => $todo->recurrence_interval,
            'recurrence_ends_at'    => $todo->recurrence_ends_at,
            'parent_task_id'        => $parentId,
            'auto_generated'        => true,
        ]);

        if ($todo->tags->isNotEmpty()) {
            $next->tags()->attach($todo->tags->pluck('id'));
        }

        $this->cache->forget([self::CACHE_KEY, $todo->user_id]);
        $this->cache->forget([self::CACHE_KEY_DUE, $todo->user_id]);
    }

    public function destroy(Todo $todo): void
    {
        $userId = $todo->user_id;
        $todo->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
        $this->cache->forget([self::CACHE_KEY_DUE, $userId]);
    }

    public function addSubtask(Todo $todo, string $title): TodoSubtask
    {
        $subtask = TodoSubtask::create([
            'todo_id' => $todo->id,
            'title' => $title,
        ]);

        $this->cache->forget([self::CACHE_KEY, $todo->user_id]);
        $this->cache->forget([self::CACHE_KEY_DUE, $todo->user_id]);

        return $subtask;
    }

    public function updateSubtask(TodoSubtask $subtask, array $data): TodoSubtask
    {
        $subtask->update($data);

        $userId = $subtask->todo->user_id;
        $this->cache->forget([self::CACHE_KEY, $userId]);
        $this->cache->forget([self::CACHE_KEY_DUE, $userId]);

        return $subtask;
    }

    public function destroySubtask(TodoSubtask $subtask): void
    {
        $userId = $subtask->todo->user_id;
        $subtask->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
        $this->cache->forget([self::CACHE_KEY_DUE, $userId]);
    }
}
