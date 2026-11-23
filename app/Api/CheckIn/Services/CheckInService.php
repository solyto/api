<?php

namespace App\Api\CheckIn\Services;

use App\Api\CheckIn\Enums\CheckInBuiltInTracker;
use App\Api\CheckIn\Models\CheckIn;
use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\CheckIn\Models\CheckInValue;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CheckInService
{
    private const string CACHE_KEY = 'checkin';

    public const string VALUES_CACHE_KEY = 'checkin_values';

    private const int CACHE_TTL = 86400;

    public function __construct(
        private readonly UserCacheService $cache,
        private readonly CheckInTrackerService $trackers,
    ) {}

    /**
     * The built-in trackers as one row per day, the shape apps before the tracker tables read.
     * It reads the `check_in` table, which every write below keeps in step with the values.
     */
    public function list(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            function () use ($user) {
                return CheckIn::forUser($user->id)->get();
            }
        );
    }

    public function find(User $user, string $date): ?CheckIn
    {
        return CheckIn::forUser($user->id)->where('date', $date.' 00:00:00')->first();
    }

    /** @return Collection<int, CheckInValue> */
    public function values(User $user, ?string $from = null, ?string $to = null): Collection
    {
        $values = $this->cache->remember(
            [self::VALUES_CACHE_KEY, $user->id],
            self::CACHE_TTL,
            function () use ($user) {
                return CheckInValue::whereIn('check_in_tracker_id', CheckInTracker::forUser($user->id)->select('id'))
                    ->orderBy('date')
                    ->get();
            }
        );

        return $values
            ->filter(fn (CheckInValue $value) => ($from === null || $value->date->format('Y-m-d') >= $from)
                && ($to === null || $value->date->format('Y-m-d') <= $to))
            ->values();
    }

    /**
     * Built-in values on one day, keyed by tracker key.
     *
     * @return array<string, int>
     */
    public function builtInValuesOn(User $user, string $date): array
    {
        return CheckInValue::query()
            ->join('check_in_trackers', 'check_in_trackers.id', '=', 'check_in_values.check_in_tracker_id')
            ->where('check_in_trackers.user_id', $user->id)
            ->whereNotNull('check_in_trackers.key')
            ->whereDate('check_in_values.date', $date)
            ->pluck('check_in_values.value', 'check_in_trackers.key')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    /**
     * Dates (Y-m-d) within [$from, $to] that the user has any check-in value for.
     *
     * @return array<int, string>
     */
    public function marks(User $user, string $from, string $to): array
    {
        return CheckInValue::whereIn('check_in_tracker_id', CheckInTracker::forUser($user->id)->select('id'))
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->unique()
            ->values()
            ->all();
    }

    public function setValue(User $user, CheckInTracker $tracker, string $date, ?int $value): ?CheckInValue
    {
        if ($value !== null && ! $tracker->type->accepts($value)) {
            throw ValidationException::withMessages(['value' => 'The value does not fit this tracker.']);
        }

        $date = Carbon::parse($date)->toDateString();
        $existing = CheckInValue::where('check_in_tracker_id', $tracker->id)->whereDate('date', $date)->first();

        if ($value === null) {
            $existing?->delete();
            $stored = null;
        } elseif ($existing) {
            $existing->update(['value' => $value]);
            $stored = $existing;
        } else {
            $stored = $this->createValue($tracker, $date, $value);
        }

        if ($tracker->key !== null) {
            $this->mirrorToCheckInTable($user, $date, $tracker->key, $value);
        }

        $this->forgetCache($user);

        return $stored;
    }

    /**
     * Saves a day the way apps before the tracker tables send it: built-in tracker keys as fields.
     *
     * @param array<string, int|null> $data
     */
    public function saveDay(User $user, string $date, array $data): CheckIn
    {
        $date = Carbon::parse($date)->toDateString();
        $trackers = $this->trackers->list($user)->whereNotNull('key')->keyBy('key');

        foreach (CheckInBuiltInTracker::keys() as $key) {
            if (array_key_exists($key, $data)) {
                $this->setValue($user, $trackers[$key], $date, $data[$key] === null ? null : (int) $data[$key]);
            }
        }

        return $this->find($user, $date) ?? $this->createEmptyDay($user, $date);
    }

    public function setBuiltInValue(User $user, string $date, string $key, ?int $value): void
    {
        $tracker = $this->trackers->list($user)->firstWhere('key', $key);

        if ($tracker) {
            $this->setValue($user, $tracker, $date, $value);
        }
    }

    // Apps before the tracker tables got a row back even for a day saved without values.
    private function createEmptyDay(User $user, string $date): CheckIn
    {
        try {
            $checkIn = CheckIn::create(['user_id' => $user->id, 'date' => $date]);
        } catch (UniqueConstraintViolationException) {
            $checkIn = $this->find($user, $date);
        }

        $this->forgetCache($user);

        return $checkIn;
    }

    private function createValue(CheckInTracker $tracker, string $date, int $value): CheckInValue
    {
        try {
            return CheckInValue::create(['check_in_tracker_id' => $tracker->id, 'date' => $date, 'value' => $value]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request stored this day first; apply this value to it.
            $existing = CheckInValue::where('check_in_tracker_id', $tracker->id)->whereDate('date', $date)->firstOrFail();
            $existing->update(['value' => $value]);

            return $existing;
        }
    }

    // Keeps the old one-row-per-day table in step, for apps that still read it and for a rollback.
    private function mirrorToCheckInTable(User $user, string $date, string $key, ?int $value): void
    {
        $checkIn = $this->find($user, $date);

        if ($checkIn) {
            $checkIn->update([$key => $value]);

            return;
        }

        if ($value === null) {
            return;
        }

        try {
            CheckIn::create(['user_id' => $user->id, 'date' => $date, $key => $value]);
        } catch (UniqueConstraintViolationException) {
            $this->find($user, $date)?->update([$key => $value]);
        }
    }

    private function forgetCache(User $user): void
    {
        $this->cache->forget([self::CACHE_KEY, $user->id]);
        $this->cache->forget([self::VALUES_CACHE_KEY, $user->id]);
    }
}
