<?php

namespace App\Api\Users\Enums;

enum UserGrowthPeriod: string
{
    case Year = 'year';
    case Month = 'month';
    case Week = 'week';
    case Day = 'day';
}
