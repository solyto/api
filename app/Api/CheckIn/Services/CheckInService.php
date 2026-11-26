<?php

namespace App\Api\CheckIn\Services;

use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\DTOs\CheckInDayDTO;
use App\Api\CheckIn\Enums\CheckInBuiltInTracker;
use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\CheckIn\Models\CheckInValue;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckInService
{
    public const string VALUES_CACHE_KEY = 'checkin_values';

    private const int CACHE_TTL = 86400;

    public function __construct(
        private readonly UserCacheService $cache,
        private readonly CheckInTrackerService $trackers,
    ) {}

    /** @return Collection<int, CheckInDayDTO> the days with built-in values, oldest first */
    public function days(User $user): Collection
    {
        $keys = CheckInTracker::forUser($user->id)->whereNotNull('key')->pluck('key', 'id');

        return $this->values($user)
            ->filter(fn (CheckInValue $value) => $keys->has($value->check_in_tracker_id))
            ->groupBy(fn (CheckInValue $value) => $value->date->format('Y-m-d'))
            ->map(fn (Collection $values, string $date) => new CheckInDayDTO(
                $date,
                $values->mapWithKeys(fn (CheckInValue $value) => [$keys[$value->check_in_tracker_id] => $value->value])->all(),
                $values->min('created_at'),
                $values->max('updated_at'),
            ))
            ->values();
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
            ->filter(fn (CheckInValue $value) => $this->isWithin($value->date->format('Y-m-d'), $from, $to))
            ->values();
    }

    /** @return array<string, int> */
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

    /** @return array<int, string> */
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

        $stored = $this->storeValue($tracker, $date, $value);
        $this->forgetCache($user);

        return $stored;
    }

    /**
     * @param  array<int, array{tracker_id: int, value: int|null}>  $values
     * @return Collection<int, CheckInValue>
     */
    public function setValues(User $user, string $date, array $values): Collection
    {
        $trackers = CheckInTracker::forUser($user->id)->whereIn('id', array_column($values, 'tracker_id'))->get()->keyBy('id');

        foreach ($values as $index => $entry) {
            if ($entry['value'] !== null && ! $trackers[$entry['tracker_id']]->type->accepts($entry['value'])) {
                throw ValidationException::withMessages(["values.{$index}.value" => 'The value does not fit this tracker.']);
            }
        }

        $stored = DB::transaction(fn () => collect($values)
            ->map(fn (array $entry) => $this->storeValue($trackers[$entry['tracker_id']], $date, $entry['value']))
            ->filter()
            ->values());

        $this->forgetCache($user);

        return $stored;
    }

    /** @param array<string, int|null> $data */
    public function saveDay(User $user, string $date, array $data): CheckInDayDTO
    {
        // $data is a day the way apps before the tracker tables send it: built-in tracker keys as fields.
        $date = Carbon::parse($date)->toDateString();
        $trackers = $this->trackers->list($user)->whereNotNull('key')->keyBy('key');

        foreach (CheckInBuiltInTracker::keys() as $key) {
            if (array_key_exists($key, $data)) {
                $this->setValue($user, $trackers[$key], $date, $data[$key] === null ? null : (int) $data[$key]);
            }
        }

        return $this->days($user)->firstWhere('date', $date) ?? new CheckInDayDTO($date, [], now(), now());
    }

    public function addToCount(User $user, CheckInTracker $tracker, string $date, int $step): void
    {
        if ($tracker->type !== CheckInTrackerType::Count) {
            throw ValidationException::withMessages(['value' => 'Only count trackers can be counted up or down.']);
        }

        $date = Carbon::parse($date)->toDateString();

        DB::transaction(function () use ($tracker, $date, $step) {
            $existing = CheckInValue::where('check_in_tracker_id', $tracker->id)->whereDate('date', $date)->lockForUpdate()->first();

            $value = $existing ? min(max(0, $existing->value + $step), CheckInTrackerType::MAX_COUNT) : null;

            // A count of 0 is stored as no value, as the daily page does.
            if ($value === 0) {
                $existing->delete();
            } elseif ($existing) {
                $existing->update(['value' => $value]);
            } elseif ($step > 0) {
                $this->createValue($tracker, $date, $step);
            }
        });

        $this->forgetCache($user);
    }

    public function setBuiltInValue(User $user, string $date, string $key, ?int $value): void
    {
        $tracker = $this->trackers->list($user)->firstWhere('key', $key);

        if ($tracker) {
            $this->setValue($user, $tracker, $date, $value);
        }
    }

    private function isWithin(string $date, ?string $from, ?string $to): bool
    {
        $afterFrom = $from === null || $date >= $from;
        $beforeTo = $to === null || $date <= $to;

        return $afterFrom && $beforeTo;
    }

    private function storeValue(CheckInTracker $tracker, string $date, ?int $value): ?CheckInValue
    {
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

        return $stored;
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

    private function forgetCache(User $user): void
    {
        $this->cache->forget([self::VALUES_CACHE_KEY, $user->id]);
    }
}
