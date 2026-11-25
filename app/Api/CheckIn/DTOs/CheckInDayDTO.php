<?php

namespace App\Api\CheckIn\DTOs;

use Carbon\CarbonInterface;

// One day of built-in tracker values in the shape apps before the tracker tables read.
class CheckInDayDTO
{
    /** @param array<string, int> $values built-in tracker key => value */
    public function __construct(
        public readonly string $date,
        public readonly array $values,
        public readonly CarbonInterface $createdAt,
        public readonly CarbonInterface $updatedAt,
    ) {}

    // Those apps never use the id; it only has to be a stable integer.
    public function id(): int
    {
        return (int) str_replace('-', '', $this->date);
    }
}
