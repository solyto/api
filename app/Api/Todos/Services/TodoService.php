<?php

namespace App\Api\Todos\Services;

use App\Api\Tags\Models\Tag;
use App\Api\Todos\Models\Todo;
use App\Api\Todos\Models\TodoCategory;
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

    public function parse(User $user, array $data): array
    {
        $data['priority'] ??= 'medium';

        if (isset($data['due_at'])) {
            $data['due_at'] = $this->resolveDate($data['due_at']);
        }

        $input = $data['title'] ?? '';

        if (preg_match_all('/#([\w-]+)/', $input, $matches)) {
            $parsedTagIds = [];
            foreach ($matches[1] as $tagName) {
                $tag = Tag::forUser($user->id)
                    ->whereRaw('LOWER(name) = ?', [strtolower($tagName)])
                    ->first()
                    ?? Tag::create(['name' => $tagName, 'user_id' => $user->id]);
                $parsedTagIds[] = $tag->id;
                $input = trim(str_replace('#' . $tagName, '', $input));
            }
            $data['tags'] = array_unique(array_merge($data['tags'] ?? [], $parsedTagIds));
        }

        if (!isset($data['category_id'])) {
            if (preg_match('/\/([\w-]+)/', $input, $match)) {
                $category = TodoCategory::forUser($user->id)
                    ->whereRaw('LOWER(title) = ?', [strtolower($match[1])])
                    ->first()
                    ?? TodoCategory::create(['title' => $match[1], 'user_id' => $user->id]);
                $data['category_id'] = $category->id;
                $input = trim(str_replace('/' . $match[1], '', $input));
            }
        }

        if (preg_match('/due:([\w.-]+)/', $input, $match)) {
            $data['due_at'] = $this->resolveDate($match[1]);
            $input = trim(preg_replace('/due:[\w.-]+/', '', $input));
        }

        if (preg_match('/repeat:(daily|weekly|monthly|yearly)/', $input, $match)) {
            if (isset($data['due_at'])) {
                $data['recurrence_frequency'] = $match[1];
            }
            $input = trim(preg_replace('/repeat:(daily|weekly|monthly|yearly)/', '', $input));
        }

        if (preg_match('/link:(\S+)/', $input, $match)) {
            $data['link'] = $match[1];
            $input = trim(preg_replace('/link:\S+/', '', $input));
        }

        $data['title'] = $input;

        if (empty($data['due_at'])) {
            $data['recurrence_frequency'] = null;
            $data['recurrence_ends_at'] = null;
        }

        return $data;
    }

    private function resolveDate(string $value): string
    {
        $relative = match (strtolower($value)) {
            'today'    => now()->toDateString(),
            'tomorrow' => now()->addDay()->toDateString(),
            default    => null,
        };

        if ($relative) {
            return $relative;
        }

        $formats = ['d.m.Y', 'd.m.y', 'Y-m-d', 'y-m-d'];

        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);

                if (strlen($format) === 5) {
                    $currentYear = now()->year;
                    $twoDigitYear = (int) $date->format('y');
                    $fullYear = (int) ($currentYear / 100) * 100 + $twoDigitYear;
                    if ($fullYear < $currentYear) {
                        $fullYear += 100;
                    }
                    $date->setYear($fullYear);
                }

                return $date->format('Y-m-d');
            } catch (\Exception) {
                continue;
            }
        }

        return $value;
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
        if (isset($data['due_at'])) {
            $data['due_at'] = $this->resolveDate($data['due_at']);
        }

        if (array_key_exists('description', $data) && empty($data['description'])) {
            $data['description'] = null;
        }

        if (array_key_exists('link', $data) && empty($data['link'])) {
            $data['link'] = null;
        }

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
