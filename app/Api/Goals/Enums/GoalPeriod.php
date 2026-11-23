<?php

namespace App\Api\Goals\Enums;

enum GoalPeriod: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    public function maxDays(): int
    {
        return match ($this) {
            self::Day => 1,
            self::Week => 7,
            self::Month => 31,
        };
    }
}
