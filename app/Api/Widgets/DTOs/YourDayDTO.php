<?php

namespace App\Api\Widgets\DTOs;

class YourDayDTO
{
    /**
     * @param  array<int, int>  $unavailableCalendars
     * @param  array<int, int>  $credentialsRejectedCalendars
     */
    public function __construct(
        public readonly array $widget,
        public readonly array $unavailableCalendars,
        public readonly array $credentialsRejectedCalendars,
    ) {}
}
