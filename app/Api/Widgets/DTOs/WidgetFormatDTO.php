<?php

namespace App\Api\Widgets\DTOs;

class WidgetFormatDTO
{
    public function __construct(
        public readonly string $timezone,
        public readonly string $locale,
        public readonly string $timeFormat,
        public readonly string $dateFormat,
    ) {}
}
