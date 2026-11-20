<?php

namespace App\Api\Goals\Enums;

enum GoalPeriod: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
}
