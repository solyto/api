<?php

namespace App\Dav\DTOs;

class CalendarImportDTO
{
    public string $jobId;
    public string $userEmail;
    public string $url;
    public string $username;
    public string $secret;
    public string $stage = 'started' | 'select' | 'calendars' | 'events' | 'finished';
    public ?array $calendars = null;
    public ?array $selectedCalendars = null;
    public int $calendarsCount = 0;
    public int $calendarsDone = 0;
    public ?string $currentCalendar = null;
    public int $eventsCount = 0;
    public int $eventsDone = 0;
}
