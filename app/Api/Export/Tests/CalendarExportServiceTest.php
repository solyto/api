<?php

use App\Api\Export\Services\CalendarExportService;
use Sabre\VObject\Reader;

function exportCalendars(\App\Api\Users\Models\User $user): \Sabre\VObject\Component\VCalendar
{
    $path = tempnam(sys_get_temp_dir(), 'calendar-export');
    app(CalendarExportService::class)->export($user, $path);
    $exported = Reader::read(file_get_contents($path));
    unlink($path);

    return $exported;
}

beforeEach(function () {
    $this->user = davUser();
    $this->calendar = 'calendars/'.$this->user->email.'/my-calendar/';
});

describe('Calendar export', function () {
    it('exports events as they are stored, with their UID, exceptions, reminders and attendees', function () {
        davRequest($this->user, 'PUT', $this->calendar.'series.ics', implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Solyto//Tests//EN',
            'BEGIN:VEVENT', 'UID:series', 'DTSTAMP:20300101T000000Z', 'DTSTART:20301014T090000Z', 'DTEND:20301014T091500Z',
            'RRULE:FREQ=DAILY;COUNT=4', 'EXDATE:20301015T090000Z', 'SUMMARY:Standup', 'ATTENDEE;CN=Jane:mailto:jane@example.org',
            'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:Reminder', 'TRIGGER:-PT10M', 'END:VALARM', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:series', 'DTSTAMP:20300101T000000Z', 'RECURRENCE-ID:20301016T090000Z',
            'DTSTART:20301016T110000Z', 'DTEND:20301016T111500Z', 'SUMMARY:Standup (moved)', 'END:VEVENT',
            'END:VCALENDAR',
        ])."\r\n", ['Content-Type' => 'text/calendar']);

        $events = collect(exportCalendars($this->user)->select('VEVENT'));
        $master = $events->first(fn ($vevent) => ! isset($vevent->{'RECURRENCE-ID'}));

        expect($events)->toHaveCount(2)
            ->and((string) $master->UID)->toBe('series')
            ->and((string) $master->EXDATE)->toBe('20301015T090000Z')
            ->and((string) $master->ATTENDEE)->toBe('mailto:jane@example.org')
            ->and(isset($master->VALARM))->toBeTrue()
            ->and(isset($master->DESCRIPTION))->toBeFalse()
            ->and((string) $events->first(fn ($vevent) => isset($vevent->{'RECURRENCE-ID'}))->SUMMARY)->toBe('Standup (moved)');
    });

    it('exports each time zone once', function () {
        $apple = file_get_contents(base_path('tests/Fixtures/dav/apple-event.ics'));
        davRequest($this->user, 'PUT', $this->calendar.'a.ics', $apple, ['Content-Type' => 'text/calendar']);
        davRequest($this->user, 'PUT', $this->calendar.'b.ics', str_replace('UID:6F1C2B54-3A1D-4C4E-9A0B-1D2E3F4A5B6C', 'UID:second', $apple), ['Content-Type' => 'text/calendar']);

        $timezones = collect(exportCalendars($this->user)->select('VTIMEZONE'))->map(fn ($tz) => (string) $tz->TZID)->all();

        expect($timezones)->toBe(['Europe/Berlin']);
    });

    it('exports the events of every calendar', function () {
        davRequest($this->user, 'MKCALENDAR', 'calendars/'.$this->user->email.'/work/');
        davRequest($this->user, 'PUT', $this->calendar.'a.ics', davIcs('private', 'Dentist'), ['Content-Type' => 'text/calendar']);
        davRequest($this->user, 'PUT', 'calendars/'.$this->user->email.'/work/b.ics', davIcs('work', 'Review'), ['Content-Type' => 'text/calendar']);

        $titles = collect(exportCalendars($this->user)->select('VEVENT'))->map(fn ($vevent) => (string) $vevent->SUMMARY)->sort()->values()->all();

        expect($titles)->toBe(['Dentist', 'Review']);
    });

    it('keeps both events when two objects share a UID', function () {
        davRequest($this->user, 'MKCALENDAR', 'calendars/'.$this->user->email.'/work/');
        davRequest($this->user, 'PUT', $this->calendar.'a.ics', davIcs('same', 'First'), ['Content-Type' => 'text/calendar']);
        davRequest($this->user, 'PUT', 'calendars/'.$this->user->email.'/work/b.ics', davIcs('same', 'Second'), ['Content-Type' => 'text/calendar']);

        $uids = collect(exportCalendars($this->user)->select('VEVENT'))->map(fn ($vevent) => (string) $vevent->UID)->all();

        expect($uids)->toHaveCount(2)
            ->and($uids[0])->toBe('same')
            ->and($uids[1])->not->toBe('same');
    });

    it('exports a task stored in a calendar as a task', function () {
        davRequest($this->user, 'PUT', $this->calendar.'todo.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Solyto//Tests//EN\r\nBEGIN:VTODO\r\nUID:todo\r\nDTSTAMP:20300101T000000Z\r\nSUMMARY:Buy milk\r\nEND:VTODO\r\nEND:VCALENDAR\r\n", ['Content-Type' => 'text/calendar']);

        $exported = exportCalendars($this->user);

        expect(count($exported->select('VEVENT')))->toBe(0)
            ->and((string) $exported->VTODO->SUMMARY)->toBe('Buy milk');
    });

    it('leaves out calendars whose invite the user has not accepted', function () {
        $owner = davUser();
        davRequest($owner, 'PUT', 'calendars/'.$owner->email.'/my-calendar/shared.ics', davIcs('shared', 'Owner event'), ['Content-Type' => 'text/calendar']);
        $calendar = app(\App\Dav\Services\DavService::class)->calendars()->list($owner)[0];
        app(\App\Dav\Services\DavService::class)->calendars()->sharing()->inviteUser($calendar, $owner, $this->user);

        expect(count(exportCalendars($this->user)->select('VEVENT')))->toBe(0);

        app(\App\Dav\Services\DavService::class)->calendars()->sharing()->acceptInvite('mailto:'.$this->user->email);

        expect((string) exportCalendars($this->user)->VEVENT->SUMMARY)->toBe('Owner event');
    });

    it('writes valid iCalendar', function () {
        davRequest($this->user, 'PUT', $this->calendar.'outlook.ics', file_get_contents(base_path('tests/Fixtures/dav/outlook-windows-timezone.ics')), ['Content-Type' => 'text/calendar']);
        davRequest($this->user, 'PUT', $this->calendar.'google.ics', file_get_contents(base_path('tests/Fixtures/dav/google-recurring.ics')), ['Content-Type' => 'text/calendar']);

        $errors = collect(exportCalendars($this->user)->validate())->filter(fn (array $message) => $message['level'] >= 3)->all();

        expect($errors)->toBe([]);
    });
});
