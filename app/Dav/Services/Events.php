<?php

namespace App\Dav\Services;

use App\Dav\Backend\AppCalendarsPDO;
use App\Dav\DTOs\EventDTO;
use App\Dav\DTOs\CalendarDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Sabre\CalDAV\Backend\PDO as SabreBackend;

class Events
{
    private readonly AppCalendarsPDO $backend;

    public function __construct(AppCalendarsPDO $backend)
    {
        $this->backend = $backend;
    }

    public function list(CalendarDTO $calendar): array
    {
        return $this->backend->getEventsCustom($calendar);
    }

    public function listExpanded(CalendarDTO $calendar, \DateTime $start, \DateTime $end): array
    {
        return $this->backend->getExpandedEventsCustom($calendar, $start, $end);
    }

    public function get(CalendarDTO $calendar, string $uri): ?EventDTO
    {
        return $this->backend->getEventByUriCustom($calendar, $uri);
    }

    public function create(CalendarDTO $calendar, EventDTO $dto): ?EventDTO
    {
        $uri = $this->backend->createEventCustom($calendar, $dto);

        return $uri ? $this->backend->getEventByUriCustom($calendar, $uri) : null;
    }

    public function update(CalendarDTO $calendar, EventDTO $dto): ?EventDTO
    {
        return $this->backend->updateEventCustom($calendar, $dto) ?
            $this->backend->getEventByUriCustom($calendar, $dto->uri) :
            null;
    }

    public function delete(CalendarDTO $calendar, EventDTO $dto): bool
    {
        return $this->backend->deleteEventCustom($calendar, $dto);
    }

    public function deleteOccurrence(CalendarDTO $calendar, string $uri, \DateTimeInterface $occurrenceDate): bool
    {
        return $this->backend->deleteOccurrenceCustom($calendar, $uri, $occurrenceDate);
    }

    public function updateOccurrence(CalendarDTO $calendar, string $uri, \DateTimeInterface $occurrenceDate, EventDTO $dto): bool
    {
        return $this->backend->updateOccurrenceCustom($calendar, $uri, $occurrenceDate, $dto);
    }
}
