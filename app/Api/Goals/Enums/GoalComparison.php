<?php

namespace App\Api\Goals\Enums;

enum GoalComparison: string
{
    case AtLeast = 'at_least';
    case AtMost = 'at_most';

    public function isMet(float $current, float $target): bool
    {
        return $this === self::AtLeast ? $current >= $target : $current <= $target;
    }
}
