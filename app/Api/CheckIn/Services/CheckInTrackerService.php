<?php

namespace App\Api\CheckIn\Services;

use App\Api\CheckIn\Enums\CheckInBuiltInTracker;
use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Exceptions\BuiltInTrackerException;
use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\Users\Models\User;
use App\Shared\Exceptions\LimitReachedException;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckInTrackerService
{
    public const int MAX_CUSTOM_TRACKERS = 50;

    public function __construct(private readonly UserCacheService $cache) {}

    /** @return Collection<int, CheckInTracker> */
    public function list(User $user): Collection
    {
        $this->provisionBuiltIns($user);

        return CheckInTracker::forUser($user->id)
            ->withCount('values')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    public function provisionBuiltIns(User $user): void
    {
        $existing = CheckInTracker::forUser($user->id)->whereNotNull('key')->pluck('key')->all();
        $missing = array_diff(CheckInBuiltInTracker::keys(), $existing);

        if ($missing === []) {
            return;
        }

        $hasNone = $existing === [];
        $nextPosition = $hasNone ? 0 : $this->nextPosition($user);

        foreach (CheckInBuiltInTracker::cases() as $builtIn) {
            if (! in_array($builtIn->value, $missing, true)) {
                continue;
            }

            $position = $hasNone ? $builtIn->position() : $nextPosition++;

            CheckInTracker::firstOrCreate(
                ['user_id' => $user->id, 'key' => $builtIn->value],
                [
                    'type' => $builtIn->type(),
                    'position' => $position,
                    'enabled' => in_array($builtIn, CheckInBuiltInTracker::DEFAULT_ENABLED, true),
                    'scored' => $builtIn->type() === CheckInTrackerType::Scale,
                ]
            );
        }
    }

    public function create(User $user, array $data): CheckInTracker
    {
        if (CheckInTracker::forUser($user->id)->whereNull('key')->count() >= self::MAX_CUSTOM_TRACKERS) {
            throw new LimitReachedException('You have reached the maximum number of trackers.');
        }

        $type = CheckInTrackerType::from($data['type']);

        $tracker = CheckInTracker::create([
            'user_id' => $user->id,
            'key' => null,
            'name' => $data['name'],
            'type' => $type,
            'icon' => $data['icon'] ?? null,
            'position' => $this->nextPosition($user),
            'enabled' => true,
            'scored' => $type === CheckInTrackerType::Scale,
        ]);

        return $tracker;
    }

    public function update(CheckInTracker $tracker, array $data): CheckInTracker
    {
        if ($tracker->key !== null) {
            unset($data['name'], $data['icon']);
        }

        if (($data['scored'] ?? false) && $tracker->type !== CheckInTrackerType::Scale) {
            throw ValidationException::withMessages(['scored' => 'Only 1–5 trackers can count towards the score.']);
        }

        $tracker->update($data);

        return $tracker->fresh();
    }

    public function delete(CheckInTracker $tracker): void
    {
        if ($tracker->key !== null) {
            throw new BuiltInTrackerException('Built-in trackers can only be disabled.');
        }

        $tracker->delete();

        // The tracker's values go with it (cascade), so the cached value list is stale now.
        $this->cache->forget([CheckInService::VALUES_CACHE_KEY, $tracker->user_id]);
    }

    /** @param array<int, int> $ids */
    public function reorder(User $user, array $ids): Collection
    {
        $owned = CheckInTracker::forUser($user->id)->pluck('id')->sort()->values()->all();
        $given = collect($ids)->map(fn ($id) => (int) $id)->sort()->values()->all();

        if ($owned !== $given) {
            throw ValidationException::withMessages(['ids' => 'The order must list each of your trackers exactly once.']);
        }

        DB::transaction(function () use ($user, $ids) {
            foreach (array_values($ids) as $position => $id) {
                CheckInTracker::forUser($user->id)->whereKey($id)->update(['position' => $position]);
            }
        });

        return $this->list($user);
    }

    /** @param array<int, string> $enabledKeys */
    public function setEnabledBuiltIns(User $user, array $enabledKeys): void
    {
        // The on/off list of an app that only knows the built-in trackers.
        $this->provisionBuiltIns($user);

        foreach (CheckInBuiltInTracker::keys() as $key) {
            CheckInTracker::forUser($user->id)->where('key', $key)->update(['enabled' => in_array($key, $enabledKeys, true)]);
        }
    }

    /** @return array<int, string> */
    public function enabledBuiltInKeys(User $user): array
    {
        return $this->list($user)
            ->filter(fn (CheckInTracker $tracker) => $tracker->key !== null && $tracker->enabled)
            ->pluck('key')
            ->values()
            ->all();
    }

    private function nextPosition(User $user): int
    {
        return (int) CheckInTracker::forUser($user->id)->max('position') + 1;
    }
}
