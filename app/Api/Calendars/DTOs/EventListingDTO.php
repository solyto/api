<?php

namespace App\Api\Calendars\DTOs;

use App\Dav\DTOs\EventDTO;

class EventListingDTO
{
    // The calendar lists hold instance ids of external calendars left out (or served from the cache)
    // because their server was unreachable; credentials rejected is the part whose server refused the account.
    /**
     * @param  array<int, EventDTO>  $events
     * @param  array<int, int>  $unavailableCalendars
     * @param  array<int, int>  $credentialsRejectedCalendars
     */
    public function __construct(
        public readonly array $events,
        public readonly array $unavailableCalendars,
        public readonly array $credentialsRejectedCalendars,
    ) {}
}
