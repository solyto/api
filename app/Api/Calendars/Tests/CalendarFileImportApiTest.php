<?php

use App\Api\Calendars\Jobs\ImportCalendarFile;
use App\Api\Calendars\Jobs\ImportCalendars;
use App\Api\Export\Services\CalendarExportService;
use App\Api\Todos\Models\Todo;
use App\Api\Users\Models\Friend;
use App\Api\Users\Models\User;
use App\Dav\DTOs\CalendarImportDTO;
use App\Dav\Exceptions\ImportFileException;
use App\Dav\Services\CalendarImport;
use App\Dav\Services\DavService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeCalDavServer;

beforeEach(function () {
    Storage::fake('local');
});

function icsFile(array $components, array $header = [], string $filename = 'holidays.ics'): UploadedFile
{
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Other App//EN', ...$header];
    foreach ($components as $component) {
        $lines = [...$lines, ...$component];
    }
    $lines[] = 'END:VCALENDAR';

    return UploadedFile::fake()->createWithContent($filename, implode("\r\n", $lines)."\r\n");
}

function icsEvent(string $uid, string $summary = 'Event', array $extra = [], string $start = 'DTSTART:20301015T100000Z'): array
{
    return ['BEGIN:VEVENT', "UID:{$uid}", 'DTSTAMP:20300101T000000Z', $start, 'DTEND:20301015T110000Z', "SUMMARY:{$summary}", ...$extra, 'END:VEVENT'];
}

function importIcs($test, User $user, UploadedFile $file, array $fields = [])
{
    return $test->actingAs($user, 'sanctum')->post('/api/v1/calendars/import/file', ['file' => $file, ...$fields], ['Accept' => 'application/json']);
}

function calendarNamed(User $user, string $name): ?array
{
    return collect(test()->actingAs($user, 'sanctum')->getJson('/api/v1/calendars')->json('data'))->firstWhere('name', $name);
}

/** @return array<int, string> */
function storedObjects(User $user, int $instanceId): array
{
    $calendarId = DB::connection('pgsql')->table('calendarinstances')->where('id', $instanceId)->value('calendarid');

    return DB::connection('pgsql')->table('calendarobjects')->where('calendarid', $calendarId)->pluck('calendardata')
        ->map(fn ($data) => is_resource($data) ? stream_get_contents($data) : $data)
        ->all();
}

function importState($test, User $user): array
{
    return $test->actingAs($user, 'sanctum')->getJson('/api/v1/calendars/import/state')->assertStatus(200)->json('data');
}

describe('Calendar file import', function () {
    it('imports the events into a new calendar named after the file calendar', function () {
        $user = makeUser();

        $response = importIcs($this, $user, icsFile([icsEvent('a', 'Dentist'), icsEvent('b', 'Gym')], ['METHOD:PUBLISH', 'X-WR-CALNAME:Holidays 2030']));

        expectApiSuccess($response, 200);
        $calendar = calendarNamed($user, 'Holidays 2030');
        expect($calendar)->not->toBeNull()
            ->and(storedObjects($user, $calendar['id']))->toHaveCount(2);
        expect(importState($this, $user))->toMatchArray([
            'stage' => 'finished',
            'source' => 'file',
            'calendars_count' => 1,
            'calendars_done' => 1,
            'events_count' => 2,
            'events_done' => 2,
            'events_skipped' => 0,
        ]);

        $titles = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/calendars/events/2030-10')->json('data'))->pluck('title');
        expect($titles)->toContain('Dentist', 'Gym');
    });

    it('uses the given name and color and numbers a name that is taken', function () {
        $user = makeUser();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/calendars', ['name' => 'Work'])->assertStatus(201);

        expectApiSuccess(importIcs($this, $user, icsFile([icsEvent('a')], ['X-WR-CALNAME:Ignored']), ['name' => 'Work', 'color' => '#FF0000']), 200);

        $calendar = calendarNamed($user, 'Work (1)');
        expect($calendar)->not->toBeNull()
            ->and($calendar['color'])->toBe('#FF0000')
            ->and(calendarNamed($user, 'Ignored'))->toBeNull();
    });

    it('names the new calendar after the file when the file has no calendar name', function () {
        $user = makeUser();

        expectApiSuccess(importIcs($this, $user, icsFile([icsEvent('a')], filename: 'Team Events.ics'), ['name' => '']), 200);

        expect(calendarNamed($user, 'Team Events'))->not->toBeNull();
    });

    it('imports into an existing calendar of the user', function () {
        $user = makeUser();
        $calendar = $this->actingAs($user, 'sanctum')->getJson('/api/v1/calendars')->json('data')[0];
        $calendarsBefore = count($this->actingAs($user, 'sanctum')->getJson('/api/v1/calendars')->json('data'));

        expectApiSuccess(importIcs($this, $user, icsFile([icsEvent('a')]), ['calendar_id' => $calendar['id']]), 200);

        expect(storedObjects($user, $calendar['id']))->toHaveCount(1)
            ->and($this->actingAs($user, 'sanctum')->getJson('/api/v1/calendars')->json('data'))->toHaveCount($calendarsBefore)
            ->and(importState($this, $user))->toMatchArray(['stage' => 'finished', 'calendars_count' => 0, 'events_done' => 1]);
    });

    it('keeps the uploaded file only until the import is done', function () {
        $user = makeUser();

        importIcs($this, $user, icsFile([icsEvent('a')]))->assertStatus(200);

        expect(Storage::disk('local')->allFiles())->toBe([]);
    });
});

describe('Calendar file import targets', function () {
    it('does not import into another user\'s calendar', function () {
        $user = makeUser();
        $other = makeUser();
        $otherCalendar = $this->actingAs($other, 'sanctum')->getJson('/api/v1/calendars')->json('data')[0];

        expectApiError(importIcs($this, $user, icsFile([icsEvent('a')]), ['calendar_id' => $otherCalendar['id']]), 404);

        expect(storedObjects($other, $otherCalendar['id']))->toBe([]);
    });

    it('does not import into a calendar shared with the user', function () {
        $owner = makeUser();
        $friend = makeUser();
        Friend::factory()->forUsers($owner, $friend)->create();
        $ownerCalendar = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/calendars')->json('data')[0];
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/calendars/'.$ownerCalendar['id'].'/share', ['friend_id' => $friend->id])->assertStatus(200);
        $sharedInstanceId = DB::connection('pgsql')->table('calendarinstances')
            ->where('principaluri', 'principals/'.$friend->email)->where('access', '>', 1)->value('id');

        expectApiError(importIcs($this, $friend, icsFile([icsEvent('a')]), ['calendar_id' => $sharedInstanceId]), 403);

        expect(storedObjects($owner, $ownerCalendar['id']))->toBe([]);
    });

    it('does not import into an external calendar', function () {
        $user = makeUser();
        $calendar = externalCalendar($user, (new FakeCalDavServer())->fake());

        expectApiError(importIcs($this, $user, icsFile([icsEvent('a')]), ['calendar_id' => $calendar->instanceId]), 403);
    });
});

describe('Calendar file import validation', function () {
    it('rejects a request without a file, with a wrong extension, or with both a calendar and a name', function () {
        $user = makeUser();
        $calendarId = $this->actingAs($user, 'sanctum')->getJson('/api/v1/calendars')->json('data')[0]['id'];

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/calendars/import/file', [])
            ->assertStatus(422)->assertJsonValidationErrors(['file']);
        importIcs($this, $user, icsFile([icsEvent('a')], filename: 'events.txt'))
            ->assertStatus(422)->assertJsonValidationErrors(['file']);
        importIcs($this, $user, icsFile([icsEvent('a')]), ['calendar_id' => $calendarId, 'name' => 'New'])
            ->assertStatus(422)->assertJsonValidationErrors(['calendar_id']);
    });

    it('rejects a file that is not iCalendar', function (string $content) {
        $user = makeUser();

        expectApiError(importIcs($this, $user, UploadedFile::fake()->createWithContent('broken.ics', $content)), 422);

        expect(Storage::disk('local')->allFiles())->toBe([]);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/calendars/import/state')->assertStatus(404);
    })->with([
        'empty' => [''],
        'text' => ['not a calendar'],
        'vCard' => ["BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Jane\r\nEND:VCARD\r\n"],
    ]);

    it('rejects a file without events', function (array $components) {
        $user = makeUser();

        expectApiError(importIcs($this, $user, icsFile($components)), 422);
    })->with([
        'empty calendar' => [[]],
        'to-dos only' => [[['BEGIN:VTODO', 'UID:t', 'DTSTAMP:20300101T000000Z', 'SUMMARY:Task', 'END:VTODO']]],
    ]);

    it('rejects a file with more events than the limit', function () {
        $user = makeUser();
        $events = array_map(fn (int $i) => icsEvent("e{$i}"), range(0, CalendarImport::MAX_FILE_OBJECTS));

        expectApiError(importIcs($this, $user, icsFile($events)), 422);

        expect(calendarNamed($user, 'holidays'))->toBeNull();
    });

    it('rejects a file with more events than the limit even when they share a UID', function () {
        $user = makeUser();
        $events = array_map(fn () => icsEvent('same-uid'), range(0, CalendarImport::MAX_FILE_OBJECTS));

        expectApiError(importIcs($this, $user, icsFile($events)), 422);

        expect(calendarNamed($user, 'holidays'))->toBeNull();
    });
});

describe('Calendar file import fidelity', function () {
    it('stores events as they are in the file', function () {
        $user = makeUser();
        $timezone = [
            'BEGIN:VTIMEZONE', 'TZID:Europe/Berlin',
            'BEGIN:DAYLIGHT', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'TZNAME:CEST', 'DTSTART:19700329T020000', 'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', 'END:DAYLIGHT',
            'BEGIN:STANDARD', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'TZNAME:CET', 'DTSTART:19701025T030000', 'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU', 'END:STANDARD',
            'END:VTIMEZONE',
        ];
        $series = [
            'BEGIN:VEVENT', 'UID:series', 'DTSTAMP:20300101T000000Z', 'DTSTART;TZID=Europe/Berlin:20301014T090000', 'DTEND;TZID=Europe/Berlin:20301014T091500',
            'RRULE:FREQ=DAILY;COUNT=30', 'EXDATE;TZID=Europe/Berlin:20301015T090000', 'SUMMARY:Standup',
            'ORGANIZER;CN=Boss:mailto:boss@example.org', 'ATTENDEE;CN=Jane;PARTSTAT=ACCEPTED:mailto:jane@example.org', 'X-OTHER-APP-FLAG:kept',
            'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:Reminder', 'TRIGGER:-PT10M', 'END:VALARM', 'END:VEVENT',
        ];
        $override = [
            'BEGIN:VEVENT', 'UID:series', 'DTSTAMP:20300101T000000Z', 'RECURRENCE-ID;TZID=Europe/Berlin:20301016T090000',
            'DTSTART;TZID=Europe/Berlin:20301016T110000', 'DTEND;TZID=Europe/Berlin:20301016T111500', 'SUMMARY:Standup (moved)', 'END:VEVENT',
        ];

        importIcs($this, $user, icsFile([$timezone, $override, $series], ['X-WR-CALNAME:Team']))->assertStatus(200);

        $objects = storedObjects($user, calendarNamed($user, 'Team')['id']);
        expect($objects)->toHaveCount(1);
        expect($objects[0])
            ->toContain('BEGIN:VTIMEZONE', 'TZID:Europe/Berlin', 'DTSTART;TZID=Europe/Berlin:20301014T090000')
            ->toContain('EXDATE;TZID=Europe/Berlin:20301015T090000', 'RECURRENCE-ID;TZID=Europe/Berlin:20301016T090000')
            ->toContain('BEGIN:VALARM', 'TRIGGER:-PT10M', 'ORGANIZER;CN=Boss:mailto:boss@example.org', 'ATTENDEE;CN=Jane;PARTSTAT=ACCEPTED:mailto:jane@example.org')
            ->toContain('X-OTHER-APP-FLAG:kept');

        $standups = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/calendars/events/2030-10')->json('data'))
            ->filter(fn (array $event) => str_starts_with($event['title'], 'Standup'));
        expect($standups->pluck('title')->filter(fn ($title) => $title === 'Standup (moved)'))->toHaveCount(1);
    });

    it('keeps only the timezones its events use', function () {
        $user = makeUser();
        $berlin = [
            'BEGIN:VTIMEZONE', 'TZID:Europe/Berlin',
            'BEGIN:DAYLIGHT', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'DTSTART:19700329T020000', 'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', 'END:DAYLIGHT',
            'BEGIN:STANDARD', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'DTSTART:19701025T030000', 'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU', 'END:STANDARD',
            'END:VTIMEZONE',
        ];
        $newYork = [
            'BEGIN:VTIMEZONE', 'TZID:America/New_York',
            'BEGIN:DAYLIGHT', 'TZOFFSETFROM:-0500', 'TZOFFSETTO:-0400', 'DTSTART:19700308T020000', 'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU', 'END:DAYLIGHT',
            'BEGIN:STANDARD', 'TZOFFSETFROM:-0400', 'TZOFFSETTO:-0500', 'DTSTART:19701101T020000', 'RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU', 'END:STANDARD',
            'END:VTIMEZONE',
        ];
        $event = [
            'BEGIN:VEVENT', 'UID:berlin', 'DTSTAMP:20300101T000000Z',
            'DTSTART;TZID=Europe/Berlin:20301014T090000', 'DTEND;TZID=Europe/Berlin:20301014T100000',
            'SUMMARY:Berlin', 'END:VEVENT',
        ];

        importIcs($this, $user, icsFile([$berlin, $newYork, $event], ['X-WR-CALNAME:Zones']))->assertStatus(200);

        $objects = storedObjects($user, calendarNamed($user, 'Zones')['id']);
        expect($objects)->toHaveCount(1)
            ->and($objects[0])->toContain('TZID:Europe/Berlin')
            ->not->toContain('TZID:America/New_York');
    });

    it('skips what it cannot store and imports the rest', function () {
        $user = makeUser();
        $components = [
            icsEvent('good', 'Good'),
            ['BEGIN:VEVENT', 'UID:no-start', 'DTSTAMP:20300101T000000Z', 'SUMMARY:No start', 'END:VEVENT'],
            ['BEGIN:VTODO', 'UID:todo', 'DTSTAMP:20300101T000000Z', 'SUMMARY:Task', 'END:VTODO'],
            icsEvent('also-good', 'Also good'),
        ];

        importIcs($this, $user, icsFile($components, ['X-WR-CALNAME:Mixed']))->assertStatus(200);

        expect(storedObjects($user, calendarNamed($user, 'Mixed')['id']))->toHaveCount(2)
            ->and(importState($this, $user))->toMatchArray(['stage' => 'finished', 'events_count' => 4, 'events_done' => 2, 'events_skipped' => 2]);
    });

    it('gives each event without a UID its own object', function () {
        $user = makeUser();
        $withoutUid = fn (string $summary) => ['BEGIN:VEVENT', 'DTSTAMP:20300101T000000Z', 'DTSTART:20301015T100000Z', "SUMMARY:{$summary}", 'END:VEVENT'];

        importIcs($this, $user, icsFile([$withoutUid('One'), $withoutUid('Two'), $withoutUid('Three')], ['X-WR-CALNAME:No UIDs']))->assertStatus(200);

        expect(storedObjects($user, calendarNamed($user, 'No UIDs')['id']))->toHaveCount(3);
    });

    it('imports its own export back', function () {
        $user = makeUser();
        storeEvent($this, $user, ['title' => 'Dentist', 'start_date' => '2030-10-15 09:30', 'end_date' => '2030-10-15 10:30']);
        storeEvent($this, $user, ['title' => 'Standup', 'start_date' => '2030-10-14 08:00', 'end_date' => '2030-10-14 08:15', 'is_recurring' => true, 'recurrence_rule' => 'FREQ=DAILY;COUNT=5']);
        $path = tempnam(sys_get_temp_dir(), 'calendar-export');
        app(CalendarExportService::class)->export($user, $path);
        $file = UploadedFile::fake()->createWithContent('export.ics', file_get_contents($path));
        unlink($path);

        importIcs($this, $user, $file, ['name' => 'Restored'])->assertStatus(200);

        $restored = calendarNamed($user, 'Restored');
        $titles = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/calendars/events/2030-10')->json('data'))
            ->where('calendar_id', $restored['id'])->pluck('title')->unique()->values()->all();
        expect(storedObjects($user, $restored['id']))->toHaveCount(2)
            ->and($titles)->toEqualCanonicalizing(['Dentist', 'Standup']);
    });

    it('lets a to-do be attached to an imported event', function () {
        $user = makeUser();
        $todo = Todo::factory()->forUser($user)->create();
        importIcs($this, $user, icsFile([icsEvent('a', 'Dentist')], ['X-WR-CALNAME:Imported']))->assertStatus(200);
        $event = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/calendars/events/2030-10')->json('data'))->firstWhere('title', 'Dentist');

        expectApiSuccess($this->actingAs($user, 'sanctum')->postJson('/api/v1/calendars/events/'.$event['uri'].'/attachments/todos', ['todo_id' => $todo->id]), 201);

        expect($this->actingAs($user, 'sanctum')->getJson('/api/v1/calendars/events/'.$event['uri'].'/attachments/todos')->json('data'))->toHaveCount(1);
    });
});

describe('Calendar import concurrency', function () {
    it('refuses a second import while one is running', function () {
        Queue::fake();
        $user = makeUser();
        importIcs($this, $user, icsFile([icsEvent('a')]))->assertStatus(200);

        expectApiError(importIcs($this, $user, icsFile([icsEvent('b')], filename: 'other.ics')), 409);
        expectApiError($this->actingAs($user, 'sanctum')->postJson('/api/v1/calendars/import', [
            'url' => 'https://remote.example.com/dav/', 'username' => 'alice', 'secret' => 'secret',
        ]), 409);
        expectApiError($this->actingAs($user, 'sanctum')->postJson('/api/v1/calendars/import/select', ['calendars' => ['Work']]), 404);

        expect(Storage::disk('local')->allFiles())->toHaveCount(1)
            ->and(importState($this, $user)['stage'])->toBe('calendars');
        Queue::assertPushed(ImportCalendarFile::class, 1);
    });

    it('allows a new import once the running one has stopped saving progress, and keeps the old job off it', function () {
        Queue::fake();
        $user = makeUser();
        importIcs($this, $user, icsFile([icsEvent('a')], ['X-WR-CALNAME:First']))->assertStatus(200);
        $staleJobId = app(DavService::class)->import()->calendars()->getState($user->id)->jobId;

        $this->travel(6)->minutes();

        expectApiSuccess(importIcs($this, $user, icsFile([icsEvent('b')], ['X-WR-CALNAME:Second'])), 200);
        (new ImportCalendarFile($user->id, $staleJobId))->handle(app(DavService::class));

        expect(calendarNamed($user, 'Second'))->toBeNull()
            ->and(importState($this, $user)['stage'])->toBe('calendars')
            ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    });

    it('keeps a CalDAV job that waited too long off a file import', function () {
        Queue::fake();
        $user = makeUser();
        Cache::set('calendar-import-'.$user->id, tap(new CalendarImportDTO(), function ($dto) {
            $dto->jobId = 'caldav-job';
            $dto->stage = 'calendars';
            $dto->selectedCalendars = [['name' => 'Remote', 'url' => 'https://remote.example.com/dav/remote/']];
            $dto->updatedAt = now()->getTimestamp();
        }));
        $this->travel(6)->minutes();
        importIcs($this, $user, icsFile([icsEvent('a')], ['X-WR-CALNAME:From File']))->assertStatus(200);
        $fileJobId = app(DavService::class)->import()->calendars()->getState($user->id)->jobId;

        (new ImportCalendars($user->id, 'caldav-job'))->handle(app(DavService::class));
        (new ImportCalendars($user->id))->handle(app(DavService::class));

        expect(calendarNamed($user, 'From File'))->toBeNull()
            ->and(calendarNamed($user, 'Remote'))->toBeNull()
            ->and(importState($this, $user)['stage'])->toBe('calendars');

        (new ImportCalendarFile($user->id, $fileJobId))->handle(app(DavService::class));

        $names = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/calendars')->json('data'))->pluck('name');
        expect($names->filter(fn ($name) => str_starts_with($name, 'From File')))->toHaveCount(1);
    });

    it('stops a CalDAV job on a failed import instead of waiting for it to finish', function () {
        $user = makeUser();
        Cache::set('calendar-import-'.$user->id, tap(new CalendarImportDTO(), function ($dto) {
            $dto->jobId = 'caldav-job';
            $dto->stage = 'failed';
        }));

        (new ImportCalendars($user->id, 'caldav-job'))->handle(app(DavService::class));

        expect(importState($this, $user)['stage'])->toBe('failed');
    });

    it('runs a CalDAV job on its own import to the end', function () {
        $user = makeUser();
        Cache::set('calendar-import-'.$user->id, tap(new CalendarImportDTO(), function ($dto) {
            $dto->jobId = 'caldav-job';
            $dto->userEmail = 'unused@example.com';
            $dto->stage = 'calendars';
            $dto->selectedCalendars = [];
        }));

        (new ImportCalendars($user->id, 'caldav-job'))->handle(app(DavService::class));

        expect(importState($this, $user)['stage'])->toBe('finished');
    });

    it('does not let a CalDAV import waiting for its selection block a file import', function () {
        $user = makeUser();
        Cache::set('calendar-import-'.$user->id, tap(new CalendarImportDTO(), function ($dto) {
            $dto->stage = 'select';
            $dto->updatedAt = now()->getTimestamp();
        }));

        expectApiSuccess(importIcs($this, $user, icsFile([icsEvent('a')])), 200);
    });
});

describe('Calendar file import failure', function () {
    it('reports a failed import and removes the file', function () {
        Queue::fake();
        $user = makeUser();
        $calendar = $this->actingAs($user, 'sanctum')->postJson('/api/v1/calendars', ['name' => 'Gone'])->json('data');
        importIcs($this, $user, icsFile([icsEvent('a')]), ['calendar_id' => $calendar['id']])->assertStatus(200);
        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/calendars/'.$calendar['id'])->assertStatus(200);

        $job = new ImportCalendarFile($user->id, app(DavService::class)->import()->calendars()->getState($user->id)->jobId);
        expect(fn () => $job->handle(app(DavService::class)))->toThrow(ImportFileException::class);
        $job->failed(null);

        expect(importState($this, $user)['stage'])->toBe('failed')
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('marks a timed-out import as failed', function () {
        Queue::fake();
        $user = makeUser();
        importIcs($this, $user, icsFile([icsEvent('a')]))->assertStatus(200);

        (new ImportCalendarFile($user->id, app(DavService::class)->import()->calendars()->getState($user->id)->jobId))->failed(null);

        expect(importState($this, $user)['stage'])->toBe('failed')
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    });
});
