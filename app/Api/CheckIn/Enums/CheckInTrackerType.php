<?php

namespace App\Api\CheckIn\Enums;

enum CheckInTrackerType: string
{
    case Scale = 'scale';
    case YesNo = 'yes_no';
    case Count = 'count';
    case Sport = 'sport';

    public const int MAX_COUNT = 1000000;

    /** @return array<int, string> */
    public static function customValues(): array
    {
        return [self::Scale->value, self::YesNo->value, self::Count->value];
    }

    public function accepts(int $value): bool
    {
        return match ($this) {
            self::Scale => $value >= 1 && $value <= 5,
            self::YesNo => $value === 1,
            self::Count => $value >= 0 && $value <= self::MAX_COUNT,
            self::Sport => $value >= 1 && $value <= 6,
        };
    }
}
