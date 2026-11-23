<?php

namespace App\Api\Alerts\Enums;

use App\Api\CheckIn\Enums\CheckInTrackerType;

enum AlertRule: string
{
    case AverageBelow = 'average_below';
    case AverageAbove = 'average_above';
    case NoEntryDays = 'no_entry_days';
    case EntryDays = 'entry_days';
    case GoalReached = 'goal_reached';

    public function isAverage(): bool
    {
        return $this === self::AverageBelow || $this === self::AverageAbove;
    }

    /** @return array<int, self> */
    public static function forTrackerType(CheckInTrackerType $type): array
    {
        // Streaks count entries, and a 1–5 entry can be a "none" rating, so 1–5 trackers only get averages.
        return match ($type) {
            CheckInTrackerType::Scale => [self::AverageBelow, self::AverageAbove],
            CheckInTrackerType::Count => [self::AverageBelow, self::AverageAbove, self::NoEntryDays, self::EntryDays],
            CheckInTrackerType::YesNo, CheckInTrackerType::Sport => [self::NoEntryDays, self::EntryDays],
        };
    }
}
