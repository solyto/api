<?php

namespace App\Api\TimeTracking\Services;

use App\Api\TimeTracking\Models\TimeTrackingCategory;
use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\TimeTracking\Models\TimeTrackingProject;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TimeTrackingService
{
    private const string CACHE_KEY_ENTRIES = 'tt_entries';
    private const string CACHE_KEY_CATEGORIES = 'tt_categories';
    private const string CACHE_KEY_PROJECTS = 'tt_projects_v2';
    private const int CACHE_TTL_ENTRIES = 300;
    private const int CACHE_TTL = 86400;

    public function __construct(private readonly UserCacheService $cache) {}

    public function listEntries(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY_ENTRIES, $user->id],
            self::CACHE_TTL_ENTRIES,
            fn() => TimeTrackingEntry::forUser($user->id)
                ->with(['project'])
                ->orderBy('started_at', 'desc')
                ->get()
        );
    }

    public function createEntry(User $user, array $data): TimeTrackingEntry
    {
        $data['user_id'] = $user->id;
        $entry = TimeTrackingEntry::create($data);
        $entry->load(['project']);

        $this->forgetUser($user->id);

        return $entry;
    }

    public function updateEntry(TimeTrackingEntry $entry, array $data): TimeTrackingEntry
    {
        $entry->update($data);
        $entry->load(['project']);

        $this->forgetUser($entry->user_id);

        return $entry;
    }

    public function destroyEntry(TimeTrackingEntry $entry): void
    {
        $userId = $entry->user_id;
        $entry->delete();

        $this->forgetUser($userId);
    }

    public function startTimer(User $user, array $data): TimeTrackingEntry
    {
        $entry = TimeTrackingEntry::create([
            'description' => $data['description'] ?? null,
            'started_at' => Carbon::now(),
            'stopped_at' => null,
            'duration_minutes' => 0,
            'has_exact_times' => true,
            'project_id' => $data['project_id'],
            'user_id' => $user->id,
        ]);

        $entry->load(['project']);

        $this->forgetUser($user->id);

        return $entry;
    }

    public function hasRunningTimer(User $user): bool
    {
        return TimeTrackingEntry::forUser($user->id)->whereNull('stopped_at')->exists();
    }

    public function runningEntry(User $user): ?TimeTrackingEntry
    {
        return TimeTrackingEntry::forUser($user->id)
            ->whereNull('stopped_at')
            ->with('project')
            ->latest('started_at')
            ->first();
    }

    // Most recently used first (latest entry start), then never used projects by title.
    /** @return Collection<int, TimeTrackingProject> */
    public function projectsByLastUse(User $user, int $limit, ?string $exceptProjectId = null): Collection
    {
        // MariaDB and SQLite both sort NULL last in descending order, so never used projects follow the used ones.
        return TimeTrackingProject::forUser($user->id)
            ->when($exceptProjectId, fn ($query) => $query->whereKeyNot($exceptProjectId))
            ->withMax('entries', 'started_at')
            ->orderByDesc('entries_max_started_at')
            ->orderBy('title')
            ->limit($limit)
            ->get();
    }

    // Stops any other running timer; a no-op while the project's own timer runs.
    public function switchTo(User $user, TimeTrackingProject $project): void
    {
        DB::transaction(function () use ($user, $project) {
            $running = TimeTrackingEntry::forUser($user->id)
                ->whereNull('stopped_at')
                ->lockForUpdate()
                ->get();

            $running->reject(fn (TimeTrackingEntry $entry) => $entry->project_id === $project->id)
                ->each(fn (TimeTrackingEntry $entry) => $this->stopTimer($entry));

            if (!$running->contains('project_id', $project->id)) {
                $this->startTimer($user, ['project_id' => $project->id]);
            }
        }, 3);
    }

    public function stopTimer(TimeTrackingEntry $entry): TimeTrackingEntry
    {
        $now = Carbon::now();
        $durationMinutes = (int) round($entry->started_at->diffInMinutes($now));

        $entry->update([
            'stopped_at' => $now,
            'duration_minutes' => max($durationMinutes, 1),
        ]);

        $entry->load(['project']);

        $this->forgetUser($entry->user_id);

        return $entry;
    }

    // A comma-separated category_ids list and a single category_id combine into one filter.
    public function statisticsCategoryIds(?string $categoryIdList, int|string|null $categoryId): array
    {
        $categoryIds = array_map('intval', array_filter(explode(',', $categoryIdList ?? '')));

        if ($categoryId !== null) {
            $categoryIds[] = (int) $categoryId;
        }

        return $categoryIds;
    }

    // $categoryIds keeps projects tagged with any of them; empty means all projects.
    public function getStatistics(User $user, Carbon $from, Carbon $to, array $categoryIds = []): array
    {
        $entries = TimeTrackingEntry::forUser($user->id)
            ->whereNotNull('stopped_at')
            ->where('started_at', '>=', $from)
            ->where('started_at', '<=', $to)
            ->when($categoryIds, fn ($query) => $this->whereProjectInCategories($query, $categoryIds))
            ->with('project')
            ->get();

        $byProject = [];
        $totalMinutes = 0;

        foreach ($entries as $entry) {
            $projectId = $entry->project_id;

            if (!isset($byProject[$projectId])) {
                $byProject[$projectId] = [
                    'project_id'    => $projectId,
                    'project_title' => $entry->project->title,
                    'color'         => $entry->project->color,
                    'total_minutes' => 0,
                ];
            }

            $byProject[$projectId]['total_minutes'] += $entry->duration_minutes;
            $totalMinutes += $entry->duration_minutes;
        }

        return [
            'by_project'    => array_values($byProject),
            'total_minutes' => $totalMinutes,
        ];
    }

    public function listCategories(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY_CATEGORIES, $user->id],
            self::CACHE_TTL,
            fn() => TimeTrackingCategory::forUser($user->id)->get()
        );
    }

    public function createCategory(User $user, array $data): TimeTrackingCategory
    {
        $data['user_id'] = $user->id;
        $category = TimeTrackingCategory::create($data);

        $this->cache->forget([self::CACHE_KEY_CATEGORIES, $user->id]);

        return $category;
    }

    public function updateCategory(TimeTrackingCategory $category, array $data): TimeTrackingCategory
    {
        $userId = $category->user_id;
        $category->update($data);

        $this->cache->forget([self::CACHE_KEY_CATEGORIES, $userId]);
        $this->cache->forget([self::CACHE_KEY_PROJECTS, $userId]);

        return $category;
    }

    public function destroyCategory(TimeTrackingCategory $category): void
    {
        $userId = $category->user_id;
        $category->delete();

        $this->cache->forget([self::CACHE_KEY_CATEGORIES, $userId]);
        $this->cache->forget([self::CACHE_KEY_PROJECTS, $userId]);
    }

    public function listProjects(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY_PROJECTS, $user->id],
            self::CACHE_TTL,
            fn() => TimeTrackingProject::forUser($user->id)
                ->with('categories')
                ->withSum('entries', 'duration_minutes')
                ->get()
        );
    }

    public function findProject(TimeTrackingProject $project): TimeTrackingProject
    {
        $project->load('categories')->loadSum('entries', 'duration_minutes');

        return $project;
    }

    public function createProject(User $user, array $data): TimeTrackingProject
    {
        $data['user_id'] = $user->id;
        $categoryIds = $data['category_ids'] ?? [];
        unset($data['category_ids']);

        $project = TimeTrackingProject::create($data);
        $project->categories()->sync($categoryIds);
        $project->load('categories')->loadSum('entries', 'duration_minutes');

        $this->forgetUser($user->id);

        return $project;
    }

    public function updateProject(TimeTrackingProject $project, array $data): TimeTrackingProject
    {
        $userId = $project->user_id;
        $categoryIds = $data['category_ids'] ?? null;
        unset($data['category_ids']);

        $project->update($data);

        if ($categoryIds !== null) {
            $project->categories()->sync($categoryIds);
        }

        $project->load('categories')->loadSum('entries', 'duration_minutes');

        $this->forgetUser($userId);

        return $project;
    }

    public function destroyProject(TimeTrackingProject $project): void
    {
        $userId = $project->user_id;
        $project->delete();

        $this->forgetUser($userId);
    }

    private function whereProjectInCategories(Builder $query, array $categoryIds): void
    {
        $query->whereHas(
            'project.categories',
            fn (Builder $categories) => $categories->whereIn('time_tracking_categories.id', $categoryIds)
        );
    }

    private function forgetUser(string $userId): void
    {
        $this->cache->forget([self::CACHE_KEY_ENTRIES, $userId]);
        $this->cache->forget([self::CACHE_KEY_PROJECTS, $userId]);
    }
}
