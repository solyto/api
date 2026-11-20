<?php

namespace App\Api\CheckIn\Enums;

enum CheckInGoalPeriod: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
}
