<?php

namespace App\Api\Alerts\Enums;

enum AlertSource: string
{
    case CheckInTracker = 'check_in_tracker';
    case Goal = 'goal';
}
