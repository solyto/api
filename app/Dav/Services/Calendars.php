<?php

namespace App\Dav\Services;

use App\Api\Users\Models\User;
use App\Dav\Backend\AppCalendarsPDO;
use App\Dav\DTOs\CalendarDTO;
use App\Dav\Helpers\DavHelper;

class Calendars
{
    private readonly Events $events;
    private readonly CalendarSharing $sharing;
    private readonly AppCalendarsPDO $backend;

    public function __construct(\PDO $pdo)
    {
        $this->backend = new AppCalendarsPDO($pdo);
        $this->events = new Events($this->backend);
        $this->sharing = new CalendarSharing($this->backend);
    }

    public function events(): Events
    {
        return $this->events;
    }

    public function sharing(): CalendarSharing
    {
        return $this->sharing;
    }

    public function list(User $user): array
    {
        return $this->backend->listCalendarsCustom(DavHelper::getPrincipalUri($user));
    }

    public function get(User $user, int $instanceId): ?CalendarDTO
    {
        return $this->backend->getCalendarByIdCustom(DavHelper::getPrincipalUri($user), $instanceId);
    }

    public function getByName(User $user, string $displayName): ?CalendarDTO
    {
        return $this->backend->getCalendarByNameCustom(DavHelper::getPrincipalUri($user), $displayName);
    }

    public function create(User $user, CalendarDTO $dto): CalendarDTO
    {
        $this->backend->createCalendarCustom(DavHelper::getPrincipalUri($user), $dto);

        return $this->getByName($user, $dto->displayName);
    }

    public function createDefaultCalendar(User $user): CalendarDTO
    {
        $default = new CalendarDTO(
            calendarId : 0,
            instanceId : 0,
            name       : 'My Calendar',
            displayName: 'My Calendar',
            color      : '#0088CC',
            description: 'Your default calendar',
            syncToken  : null,
            uri        : null,
            isShared   : false,
            isDefault  : true,
        );

        return $this->create($user, $default);
    }

    public function update(User $user, int $calendarId, CalendarDTO $dto): ?CalendarDTO
    {
        $calendar = $this->get($user, $calendarId);

        if (!$calendar) {
            return null;
        }

        $this->backend->updateCalendarCustom($dto);

        return $this->get($user, $calendarId);
    }

    public function delete(CalendarDTO $calendar): void
    {
        $this->backend->deleteCalendarCustom($calendar);
    }
}
