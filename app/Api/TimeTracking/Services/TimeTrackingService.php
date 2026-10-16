<?php

namespace App\Api\TimeTracking\Services;

use App\Api\TimeTracking\Models\TimeTrackingCategory;
use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\TimeTracking\Models\TimeTrackingProject;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TimeTrackingService
{
    private const string CACHE_KEY_ENTRIES = 'tt_entries';
    private const string CACHE_KEY_CATEGORIES = 'tt_categories';
    private const string CACHE_KEY_PROJECTS = 'tt_projects';
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

    /**
     * @param  int[]  $categoryIds  Keeps projects tagged with any of these categories; empty means all.
     */
    public function getStatistics(User $user, Carbon $from, Carbon $to, array $categoryIds = []): array
    {
        $entries = TimeTrackingEntry::forUser($user->id)
            ->whereNotNull('stopped_at')
            ->where('started_at', '>=', $from)
            ->where('started_at', '<=', $to)
            ->when($categoryIds, fn ($query) => $query->whereHas('project.categories', fn ($q) => $q->whereIn('time_tracking_categories.id', $categoryIds)))
            ->with(['project.categories'])
            ->get();

        $byProject = [];
        $totalMinutes = 0;

        foreach ($entries as $entry) {
            $projectId = $entry->project_id;

            if (!isset($byProject[$projectId])) {
                $byProject[$projectId] = [
                    'project_id'    => $projectId,
                    'project_title' => $entry->project->title,
                    // Projects have no colour picker in the app yet, so fall back to the first category's colour as in 1.0.15.
                    'color'         => $entry->project->color ?? $entry->project->categories->first()?->color,
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

        return $category;
    }

    public function destroyCategory(TimeTrackingCategory $category): void
    {
        $userId = $category->user_id;
        $category->delete();

        $this->cache->forget([self::CACHE_KEY_CATEGORIES, $userId]);
    }

    public function listProjects(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY_PROJECTS, $user->id],
            self::CACHE_TTL,
            fn() => TimeTrackingProject::forUser($user->id)
                ->with(['categories', 'entries'])
                ->get()
        );
    }

    public function findProject(TimeTrackingProject $project): TimeTrackingProject
    {
        $project->load(['categories', 'entries']);

        return $project;
    }

    public function createProject(User $user, array $data): TimeTrackingProject
    {
        $data['user_id'] = $user->id;
        $categoryIds = $data['category_ids'] ?? [];
        unset($data['category_ids']);

        $project = TimeTrackingProject::create($data);
        $project->categories()->sync($categoryIds);
        $project->load(['categories', 'entries']);

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

        $project->load(['categories', 'entries']);

        $this->forgetUser($userId);

        return $project;
    }

    public function destroyProject(TimeTrackingProject $project): void
    {
        $userId = $project->user_id;
        $project->delete();

        $this->forgetUser($userId);
    }

    private function forgetUser(string $userId): void
    {
        $this->cache->forget([self::CACHE_KEY_ENTRIES, $userId]);
        $this->cache->forget([self::CACHE_KEY_PROJECTS, $userId]);
    }
}
