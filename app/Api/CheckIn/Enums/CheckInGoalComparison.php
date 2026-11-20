<?php

namespace App\Api\CheckIn\Enums;

enum CheckInGoalComparison: string
{
    case AtLeast = 'at_least';
    case AtMost = 'at_most';
}
