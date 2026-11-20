<?php

namespace App\Api\CheckIn\Services;

use App\Api\CheckIn\Enums\CheckInBuiltInTracker;
use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\Users\Models\User;
use App\Api\Users\Models\UserSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckInTrackerService
{
    public const int MAX_CUSTOM_TRACKERS = 50;

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

        $nextPosition = $existing === [] ? 0 : $this->nextPosition($user);

        foreach (CheckInBuiltInTracker::cases() as $builtIn) {
            if (! in_array($builtIn->value, $missing, true)) {
                continue;
            }

            CheckInTracker::firstOrCreate(
                ['user_id' => $user->id, 'key' => $builtIn->value],
                [
                    'type' => $builtIn->type(),
                    'position' => $existing === [] ? $builtIn->position() : $nextPosition++,
                    'enabled' => in_array($builtIn->value, CheckInBuiltInTracker::DEFAULT_ENABLED, true),
                    'scored' => $builtIn->type() === CheckInTrackerType::Scale,
                ]
            );
        }
    }

    public function create(User $user, array $data): CheckInTracker
    {
        if (CheckInTracker::forUser($user->id)->whereNull('key')->count() >= self::MAX_CUSTOM_TRACKERS) {
            throw ValidationException::withMessages(['name' => 'You have reached the maximum number of trackers.']);
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

        if ($tracker->key !== null && array_key_exists('enabled', $data)) {
            $this->mirrorEnabledSetting($tracker->user()->firstOrFail());
        }

        return $tracker->fresh();
    }

    public function isBuiltIn(CheckInTracker $tracker): bool
    {
        return $tracker->key !== null;
    }

    public function delete(CheckInTracker $tracker): void
    {
        $tracker->delete();
    }

    /** @param array<int, int> $ids */
    public function reorder(User $user, array $ids): Collection
    {
        $owned = CheckInTracker::forUser($user->id)->pluck('id')->sort()->values()->all();
        $given = collect($ids)->map(fn ($id) => (int) $id)->sort()->values()->all();

        if ($owned !== $given) {
            throw ValidationException::withMessages(['ids' => 'The order must list each of your trackers exactly once.']);
        }

        DB::transaction(function () use ($ids) {
            foreach (array_values($ids) as $position => $id) {
                CheckInTracker::whereKey($id)->update(['position' => $position]);
            }
        });

        return $this->list($user);
    }

    /**
     * Applies the on/off list of an app that only knows the built-in trackers.
     *
     * @param array<int, string> $enabledKeys
     */
    public function setEnabledBuiltIns(User $user, array $enabledKeys): void
    {
        $this->provisionBuiltIns($user);

        foreach (CheckInBuiltInTracker::keys() as $key) {
            CheckInTracker::forUser($user->id)->where('key', $key)->update(['enabled' => in_array($key, $enabledKeys, true)]);
        }

        $this->mirrorEnabledSetting($user);
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

    // Keeps the old settings JSON current, so a rollback to the previous release keeps the on/off state.
    private function mirrorEnabledSetting(User $user): void
    {
        $settings = UserSettings::firstWhere('user_id', $user->id);

        if (! $settings) {
            return;
        }

        $enabled = CheckInTracker::forUser($user->id)
            ->whereNotNull('key')
            ->where('enabled', true)
            ->orderBy('position')
            ->pluck('key')
            ->all();

        $settings->update(['check_in_settings' => [...($settings->check_in_settings ?? []), 'enabled_trackers' => $enabled]]);
    }
}
