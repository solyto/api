<?php

use App\Api\Calendars\Services\CalendarService;
use App\Api\CheckIn\DTOs\CheckInDayDTO;
use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\CheckIn\Models\CheckInValue;
use App\Api\CheckIn\Services\CheckInService;
use App\Api\CheckIn\Services\CheckInTrackerService;
use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\TimeTracking\Models\TimeTrackingProject;
use App\Api\Todos\Models\Todo;
use App\Api\Users\Models\User;
use Carbon\Carbon;

function widgetUser(array $settings = []): User
{
    $user = makeUser();
    $enabledTrackers = $settings['check_in_settings']['enabled_trackers'] ?? null;
    unset($settings['check_in_settings']['enabled_trackers']);
    $user->settings()->update(array_merge([
        'timezone' => 'UTC',
        'language' => 'en',
        'date_format' => 'dd.mm.YYYY',
        'time_format' => 'H:i',
    ], $settings));

    if ($enabledTrackers !== null) {
        app(CheckInTrackerService::class)->setEnabledBuiltIns($user, $enabledTrackers);
    }

    return $user->fresh();
}

function widgetTodo(User $user, string $title, ?string $dueAt, bool $completed = false, array $attributes = []): Todo
{
    return Todo::factory()->forUser($user)->create(array_merge([
        'title' => $title,
        'due_at' => $dueAt,
        'is_completed' => $completed,
    ], $attributes));
}

function yourDay($test, User $user): array
{
    $response = bearer($test, sanctumToken($user))->getJson('/api/v1/widgets/your-day');
    expectApiSuccess($response);

    return $response->json('data');
}

function widgetSection(array $data, string $key): ?array
{
    return collect($data['sections'])->firstWhere('key', $key);
}

function widgetProject(User $user, string $title, ?string $color = '#336699'): TimeTrackingProject
{
    return TimeTrackingProject::factory()->forUser($user)->create(['title' => $title, 'color' => $color]);
}

function widgetEntry(User $user, TimeTrackingProject $project, string $startedAt, bool $running = false, ?string $description = null): TimeTrackingEntry
{
    $factory = TimeTrackingEntry::factory()->forUser($user)->forProject($project);

    return ($running ? $factory->running() : $factory)->create([
        'started_at' => $startedAt,
        'description' => $description,
    ]);
}

function timeTrackingWidget($test, User $user, ?string $token = null): array
{
    $response = bearer($test, $token ?? sanctumToken($user))->getJson('/api/v1/widgets/time-tracking');
    expectApiSuccess($response);

    return $response->json('data');
}

function runningEntries(User $user)
{
    return TimeTrackingEntry::forUser($user->id)->whereNull('stopped_at')->get();
}

function checkInWidget($test, User $user, ?string $token = null): array
{
    $response = bearer($test, $token ?? sanctumToken($user))->getJson('/api/v1/widgets/check-in');
    expectApiSuccess($response);

    return $response->json('data');
}

function checkInRow(array $data, string $tracker): ?array
{
    return collect(widgetSection($data, 'today')['rows'] ?? [])->firstWhere('id', $tracker);
}

function widgetCheckIn(User $user, string $date, array $values = []): CheckInDayDTO
{
    return app(CheckInService::class)->saveDay($user, $date, $values);
}

function setCheckIn($test, User $user, string $tracker, mixed $body, ?string $token = null)
{
    return bearer($test, $token ?? sanctumToken($user))->postJson('/api/v1/widgets/actions/check_in.set/'.$tracker, $body);
}

function userCheckIns(User $user)
{
    return app(CheckInService::class)->days($user);
}

afterEach(function () {
    Carbon::setTestNow();
});

describe('GET /widgets/your-day', function () {
    it('returns today, due and upcoming in that order with the right rows', function () {
        Carbon::setTestNow('2026-08-14 07:00:00');
        $user = widgetUser();

        storeEvent($this, $user, ['title' => 'Dentist', 'start_date' => '2026-08-14 09:30', 'end_date' => '2026-08-14 10:30']);
        storeEvent($this, $user, ['title' => 'Holiday', 'start_date' => '2026-08-14', 'end_date' => '2026-08-14', 'is_all_day' => true]);
        storeEvent($this, $user, ['title' => 'Meeting', 'start_date' => '2026-08-15 09:00', 'end_date' => '2026-08-15 10:00']);
        storeEvent($this, $user, ['title' => 'Trip', 'start_date' => '2026-08-15', 'end_date' => '2026-08-16', 'is_all_day' => true]);
        storeEvent($this, $user, ['title' => 'Far away', 'start_date' => '2026-08-18 09:00', 'end_date' => '2026-08-18 10:00']);

        $overdue = widgetTodo($user, 'Overdue', '2026-08-10');
        widgetTodo($user, 'Due today', '2026-08-14');
        widgetTodo($user, 'Due tomorrow', '2026-08-15');
        widgetTodo($user, 'Completed', '2026-08-12', true);
        widgetTodo($user, 'No date', null);
        widgetTodo(makeUser(), 'Foreign', '2026-08-14');

        $data = yourDay($this, $user);

        expect($data['kind'])->toBe('your-day')
            ->and($data['schema_version'])->toBe(1)
            ->and($data['generated_at'])->toBe('2026-08-14T07:00:00+00:00')
            ->and($data['labels'])->toBe(['title' => 'Your Day', 'empty' => 'Nothing on your plate today.'])
            ->and(collect($data['sections'])->pluck('key')->all())->toBe(['today', 'due', 'upcoming'])
            ->and(collect($data['sections'])->pluck('title')->all())->toBe(['Events', 'Due today', 'Coming up'])
            ->and(collect(widgetSection($data, 'today')['rows'])->pluck('title')->all())->toBe(['Holiday', 'Dentist'])
            ->and(collect(widgetSection($data, 'due')['rows'])->pluck('title')->all())->toBe(['Overdue', 'Due today'])
            ->and(collect(widgetSection($data, 'upcoming')['rows'])->pluck('title')->all())->toBe(['Trip', 'Meeting']);

        expect(widgetSection($data, 'due')['rows'][0])->toBe([
            'id' => $overdue->id,
            'title' => 'Overdue',
            'subtitle' => 'Overdue · 10.08',
            'at' => '2026-08-10',
            'all_day' => true,
            'icon' => 'todo',
            'action' => ['type' => 'check', 'key' => 'todo.complete', 'checked' => false],
        ])->and(widgetSection($data, 'due')['rows'][1])->not->toHaveKey('subtitle');

        $upcoming = widgetSection($data, 'upcoming')['rows'];
        expect($upcoming[0]['time_label'])->toBe('15.08')
            ->and($upcoming[1]['time_label'])->toBe('15.08 09:00')
            ->and($upcoming[1])->not->toHaveKey('route');
    });

    it('keeps the whole response body byte for byte', function () {
        Carbon::setTestNow('2026-08-14 07:00:00');
        $user = widgetUser(['timezone' => 'Europe/Berlin', 'language' => 'de', 'time_format' => 'g:i A', 'date_format' => 'mm/dd/YYYY']);
        storeEvent($this, $user, ['title' => 'Dentist', 'start_date' => '2026-08-14 09:30', 'end_date' => '2026-08-14 10:30']);
        storeEvent($this, $user, ['title' => 'Holiday', 'start_date' => '2026-08-14', 'end_date' => '2026-08-14', 'is_all_day' => true]);
        storeEvent($this, $user, ['title' => 'Trip', 'start_date' => '2026-08-15', 'end_date' => '2026-08-16', 'is_all_day' => true]);
        storeEvent($this, $user, ['title' => 'Meeting', 'start_date' => '2026-08-15 09:00', 'end_date' => '2026-08-15 10:00']);
        $todo = widgetTodo($user, 'Overdue', '2026-08-10');
        $eventUris = collect(app(CalendarService::class)->listWidgetEvents($user)->events)->mapWithKeys(fn ($event) => [strtolower($event->title) => $event->uri]);

        $body = bearer($this, sanctumToken($user))->getJson('/api/v1/widgets/your-day')->getContent();

        expect($body)->toBe(strtr('{"success":true,"message":"Widget data retrieved successfully.","data":{"kind":"your-day","schema_version":1,"generated_at":"2026-08-14T09:00:00+02:00","refresh_at":"2026-08-15T00:00:00+02:00","labels":{"title":"Dein Tag","empty":"Heute steht nichts an."},"sections":[{"key":"today","title":"Termine","rows":[{"id":"{holiday}#20260814T000000","title":"Holiday","time_label":"ganzt\u00e4gig","at":"2026-08-14","until":"2026-08-14","all_day":true,"icon":"event"},{"id":"{dentist}#20260814T093000","title":"Dentist","time_label":"9:30 AM","at":"2026-08-14T09:30:00+02:00","until":"2026-08-14T10:30:00+02:00","all_day":false,"icon":"event"}]},{"key":"due","title":"Heute f\u00e4llig","rows":[{"id":"{todo}","title":"Overdue","subtitle":"\u00dcberf\u00e4llig \u00b7 08\/10","at":"2026-08-10","all_day":true,"icon":"todo","action":{"type":"check","key":"todo.complete","checked":false}}]},{"key":"upcoming","title":"Demn\u00e4chst","rows":[{"id":"{trip}#20260815T000000","title":"Trip","time_label":"08\/15","at":"2026-08-15","until":"2026-08-16","all_day":true,"icon":"event"},{"id":"{meeting}#20260815T090000","title":"Meeting","time_label":"08\/15 9:00 AM","at":"2026-08-15T09:00:00+02:00","until":"2026-08-15T10:00:00+02:00","all_day":false,"icon":"event"}]}]},"timestamp":"2026-08-14T07:00:00.000000Z","meta":{"unavailable_calendars":[],"credentials_rejected_calendars":[]}}', [
            '{holiday}' => $eventUris['holiday'],
            '{dentist}' => $eventUris['dentist'],
            '{trip}' => $eventUris['trip'],
            '{meeting}' => $eventUris['meeting'],
            '{todo}' => $todo->id,
        ]));
    });

    it('sends all-day events and todos as dates and timed events as timestamps', function () {
        Carbon::setTestNow('2026-08-14 07:00:00');
        $user = widgetUser(['timezone' => 'Europe/Berlin']);

        storeEvent($this, $user, ['title' => 'Dentist', 'start_date' => '2026-08-14 09:30', 'end_date' => '2026-08-14 10:30']);
        storeEvent($this, $user, ['title' => 'Holiday', 'start_date' => '2026-08-14', 'end_date' => '2026-08-14', 'is_all_day' => true]);

        [$holiday, $dentist] = widgetSection(yourDay($this, $user), 'today')['rows'];

        expect($holiday)->toMatchArray(['at' => '2026-08-14', 'until' => '2026-08-14', 'all_day' => true, 'icon' => 'event', 'time_label' => 'all day'])
            ->and($dentist)->toMatchArray(['at' => '2026-08-14T09:30:00+02:00', 'until' => '2026-08-14T10:30:00+02:00', 'all_day' => false, 'time_label' => '09:30']);
    });

    it('expands recurring events', function () {
        Carbon::setTestNow('2026-08-14 07:00:00');
        $user = widgetUser();

        storeEvent($this, $user, ['title' => 'Standup', 'start_date' => '2026-08-10 08:00', 'end_date' => '2026-08-10 08:15', 'is_recurring' => true, 'recurrence_rule' => 'FREQ=DAILY']);

        $data = yourDay($this, $user);

        expect(collect(widgetSection($data, 'today')['rows'])->pluck('at')->all())->toBe(['2026-08-14T08:00:00+00:00'])
            ->and(collect(widgetSection($data, 'upcoming')['rows'])->pluck('id')->unique())->toHaveCount(count(widgetSection($data, 'upcoming')['rows']));
    });

    it('takes today from the user timezone', function (string $timezone, array $expected) {
        Carbon::setTestNow('2026-08-14 20:00:00');
        $user = widgetUser(['timezone' => $timezone]);

        storeEvent($this, $user, ['title' => 'Breakfast', 'start_date' => '2026-08-15 09:00', 'end_date' => '2026-08-15 10:00']);
        widgetTodo($user, 'Due 14th', '2026-08-14');
        widgetTodo($user, 'Due 15th', '2026-08-15');

        $data = yourDay($this, $user);

        expect($data['refresh_at'])->toBe($expected['refresh_at'])
            ->and(collect(widgetSection($data, 'today')['rows'] ?? [])->pluck('title')->all())->toBe($expected['today'])
            ->and(collect(widgetSection($data, 'upcoming')['rows'] ?? [])->pluck('title')->all())->toBe($expected['upcoming'])
            ->and(collect(widgetSection($data, 'due')['rows'])->map(fn ($row) => [$row['title'], $row['subtitle'] ?? null])->all())->toBe($expected['due']);
    })->with([
        'Pacific/Auckland' => ['Pacific/Auckland', [
            'refresh_at' => '2026-08-16T00:00:00+12:00',
            'today' => ['Breakfast'],
            'upcoming' => [],
            'due' => [['Due 14th', 'Overdue · 14.08'], ['Due 15th', null]],
        ]],
        'America/Los_Angeles' => ['America/Los_Angeles', [
            'refresh_at' => '2026-08-15T00:00:00-07:00',
            'today' => [],
            'upcoming' => ['Breakfast'],
            'due' => [['Due 14th', null]],
        ]],
    ]);

    it('leaves out empty sections', function () {
        Carbon::setTestNow('2026-08-14 07:00:00');
        $user = widgetUser();
        widgetTodo($user, 'Due tomorrow', '2026-08-15');

        $data = yourDay($this, $user);

        expect($data['sections'])->toBe([])
            ->and($data['labels']['empty'])->toBe('Nothing on your plate today.');
    });

    it('caps a section at 20 rows', function () {
        Carbon::setTestNow('2026-08-14 07:00:00');
        $user = widgetUser();
        foreach (range(1, 25) as $day) {
            widgetTodo($user, 'Todo '.$day, Carbon::parse('2026-07-01')->addDays($day)->toDateString());
        }

        $rows = widgetSection(yourDay($this, $user), 'due')['rows'];

        expect($rows)->toHaveCount(20)
            ->and($rows[0]['title'])->toBe('Todo 1');
    });

    it('follows the user language, date format and 12-hour time', function () {
        Carbon::setTestNow('2026-08-14 07:00:00');
        $user = widgetUser(['language' => 'de', 'time_format' => 'g:i A', 'date_format' => 'mm/dd/YYYY']);

        storeEvent($this, $user, ['title' => 'Dentist', 'start_date' => '2026-08-14 09:30', 'end_date' => '2026-08-14 10:30']);
        storeEvent($this, $user, ['title' => 'Dinner', 'start_date' => '2026-08-15 19:00', 'end_date' => '2026-08-15 21:00']);
        widgetTodo($user, 'Overdue', '2026-08-10');

        $data = yourDay($this, $user);

        expect($data['labels'])->toBe(['title' => 'Dein Tag', 'empty' => 'Heute steht nichts an.'])
            ->and(collect($data['sections'])->pluck('title')->all())->toBe(['Termine', 'Heute fällig', 'Demnächst'])
            ->and(widgetSection($data, 'today')['rows'][0]['time_label'])->toBe('9:30 AM')
            ->and(widgetSection($data, 'upcoming')['rows'][0]['time_label'])->toBe('08/15 7:00 PM')
            ->and(widgetSection($data, 'due')['rows'][0]['subtitle'])->toBe('Überfällig · 08/10');
    });
});

describe('POST /widgets/actions/todo.complete/{todo}', function () {
    it('completes the owner\'s todo', function () {
        $user = widgetUser();
        $todo = widgetTodo($user, 'Call mom', today()->toDateString());

        $response = bearer($this, sanctumToken($user))->postJson('/api/v1/widgets/actions/todo.complete/'.$todo->id);

        expectApiSuccess($response);
        $response->assertJsonPath('data', null);
        expect($todo->fresh()->is_completed)->toBeTrue()
            ->and($todo->fresh()->completed_at)->not->toBeNull();
    });

    it('rejects another user\'s todo with 403', function () {
        $todo = widgetTodo(widgetUser(), 'Not yours', today()->toDateString());

        expectApiError(bearer($this, sanctumToken(widgetUser()))->postJson('/api/v1/widgets/actions/todo.complete/'.$todo->id), 403);
        expect($todo->fresh()->is_completed)->toBeFalse();
    });

    it('answers 404 for a missing todo', function () {
        expectApiError(bearer($this, sanctumToken(widgetUser()))->postJson('/api/v1/widgets/actions/todo.complete/'.Str::uuid()), 404);
    });

    it('changes nothing when the todo is already completed', function () {
        $user = widgetUser();
        $completedAt = now()->subDay()->startOfSecond();
        $todo = widgetTodo($user, 'Done', today()->toDateString(), true, [
            'completed_at' => $completedAt,
            'recurrence_frequency' => 'daily',
            'recurrence_interval' => 1,
        ]);

        expectApiSuccess(bearer($this, sanctumToken($user))->postJson('/api/v1/widgets/actions/todo.complete/'.$todo->id));

        expect($todo->fresh()->completed_at->equalTo($completedAt))->toBeTrue()
            ->and(Todo::forUser($user->id)->count())->toBe(1);
    });

    it('spawns the next occurrence of a recurring todo', function () {
        Carbon::setTestNow('2026-08-14 07:00:00');
        $user = widgetUser();
        $todo = widgetTodo($user, 'Water plants', '2026-08-14', false, [
            'recurrence_frequency' => 'weekly',
            'recurrence_interval' => 1,
        ]);

        expectApiSuccess(bearer($this, sanctumToken($user))->postJson('/api/v1/widgets/actions/todo.complete/'.$todo->id));

        $next = Todo::forUser($user->id)->where('is_completed', false)->sole();
        expect($next->title)->toBe('Water plants')
            ->and($next->due_at->toDateString())->toBe('2026-08-21');
    });
});

describe('Widget routes and tokens', function () {
    it('work with a widget token and with a normal token', function (string $kind) {
        $user = widgetUser();
        $token = $kind === 'widget' ? issueWidgetToken($this, sessionToken($user)) : sanctumToken($user);
        $todo = widgetTodo($user, 'Call mom', today()->toDateString());

        expectApiSuccess(bearer($this, $token)->getJson('/api/v1/widgets/your-day'));
        expectApiSuccess(bearer($this, $token)->postJson('/api/v1/widgets/actions/todo.complete/'.$todo->id));
    })->with(['widget', 'normal']);

    it('keeps a widget token out of non-widget routes', function () {
        $user = widgetUser();
        $token = issueWidgetToken($this, sessionToken($user));
        $todo = widgetTodo($user, 'Call mom', today()->toDateString());

        expectApiError(bearer($this, $token)->getJson('/api/v1/todos'), 401);
        expectApiError(bearer($this, $token)->putJson('/api/v1/todos/'.$todo->id, ['is_completed' => true]), 401);
        expectApiError(bearer($this, $token)->getJson('/api/v1/calendars/events/widget'), 401);
    });

    it('rejects requests without a token', function () {
        expectApiError($this->getJson('/api/v1/widgets/your-day'), 401);
    });
});

describe('GET /widgets/time-tracking', function () {
    it('returns the running timer and the other projects', function () {
        Carbon::setTestNow('2026-08-14 07:00:00');
        $user = widgetUser(['timezone' => 'Europe/Berlin']);
        $writing = widgetProject($user, 'Writing', '#AA3300');
        $reading = widgetProject($user, 'Reading');
        widgetEntry($user, $reading, '2026-08-13 10:00:00');
        $entry = widgetEntry($user, $writing, '2026-08-14 06:30:00', true, 'Chapter 3');

        $data = timeTrackingWidget($this, $user);

        expect($data['kind'])->toBe('time-tracking')
            ->and($data['schema_version'])->toBe(1)
            ->and($data['generated_at'])->toBe('2026-08-14T09:00:00+02:00')
            ->and($data)->not->toHaveKey('refresh_at')
            ->and($data['labels'])->toBe(['title' => 'Time Tracking', 'empty' => 'No projects yet.'])
            ->and(collect($data['sections'])->pluck('key')->all())->toBe(['running', 'projects'])
            ->and(widgetSection($data, 'running')['rows'])->toBe([[
                'id' => $entry->id,
                'title' => 'Writing',
                'subtitle' => 'Chapter 3',
                'at' => '2026-08-14T08:30:00+02:00',
                'live' => 'elapsed',
                'all_day' => false,
                'color' => '#AA3300',
                'icon' => 'timer',
                'route' => ['key' => 'time_tracking_project', 'id' => $writing->id],
                'action' => ['type' => 'button', 'key' => 'time_tracking.stop', 'icon' => 'stop'],
            ]])
            ->and(widgetSection($data, 'projects')['rows'])->toBe([[
                'id' => $reading->id,
                'title' => 'Reading',
                'all_day' => false,
                'color' => '#336699',
                'route' => ['key' => 'time_tracking_project', 'id' => $reading->id],
                'action' => ['type' => 'button', 'key' => 'time_tracking.start', 'icon' => 'play'],
            ]]);
    });

    it('leaves out the running section and the subtitle when there is nothing to show', function () {
        $user = widgetUser();
        $project = widgetProject($user, 'Writing');
        widgetEntry($user, $project, '2026-08-13 10:00:00');

        $data = timeTrackingWidget($this, $user);

        expect(collect($data['sections'])->pluck('key')->all())->toBe(['projects']);

        $entry = widgetEntry($user, $project, now()->subHour()->toDateTimeString(), true);
        $row = widgetSection(timeTrackingWidget($this, $user), 'running')['rows'][0];

        expect($row['id'])->toBe($entry->id)
            ->and($row)->not->toHaveKey('subtitle');
    });

    it('returns no sections without projects', function () {
        $data = timeTrackingWidget($this, widgetUser());

        expect($data['sections'])->toBe([]);
    });

    it('orders projects by last use, then unused ones by title, capped at 20', function () {
        $user = widgetUser();
        foreach (range(1, 22) as $i) {
            widgetProject($user, sprintf('Unused %02d', $i));
        }
        $old = widgetProject($user, 'Old');
        $recent = widgetProject($user, 'Recent');
        $running = widgetProject($user, 'Running');
        widgetEntry($user, $old, '2026-01-01 10:00:00');
        widgetEntry($user, $old, '2026-02-01 10:00:00');
        widgetEntry($user, $recent, '2026-07-01 10:00:00');
        widgetEntry($user, $running, '2026-08-01 10:00:00', true);

        $titles = collect(widgetSection(timeTrackingWidget($this, $user), 'projects')['rows'])->pluck('title');

        expect($titles)->toHaveCount(20)
            ->and($titles->take(4)->all())->toBe(['Recent', 'Old', 'Unused 01', 'Unused 02'])
            ->and($titles->last())->toBe('Unused 18')
            ->and($titles)->not->toContain('Running');
    });

    it('leaves out invalid colors', function (?string $color) {
        $user = widgetUser();
        widgetProject($user, 'Writing', $color);

        expect(widgetSection(timeTrackingWidget($this, $user), 'projects')['rows'][0])->not->toHaveKey('color');
    })->with([null, 'red', '#abc', '#GG0000', '336699']);

    it('follows the user language', function () {
        $user = widgetUser(['language' => 'de']);
        $project = widgetProject($user, 'Writing');
        widgetProject($user, 'Reading');
        widgetEntry($user, $project, now()->subHour()->toDateTimeString(), true);

        $data = timeTrackingWidget($this, $user);

        expect($data['labels'])->toBe(['title' => 'Zeiterfassung', 'empty' => 'Noch keine Projekte.'])
            ->and(collect($data['sections'])->pluck('title')->all())->toBe(['Läuft', 'Projekte']);
    });

    it('falls back to English for a language the app does not support', function () {
        $user = widgetUser(['language' => 'xx']);
        widgetProject($user, 'Writing');

        expect(timeTrackingWidget($this, $user)['labels'])->toBe(['title' => 'Time Tracking', 'empty' => 'No projects yet.']);
    });
});

describe('POST /widgets/actions/time_tracking.start/{project}', function () {
    it('starts a timer when none runs', function () {
        Carbon::setTestNow('2026-08-14 07:00:00');
        $user = widgetUser();
        $project = widgetProject($user, 'Writing');

        $response = bearer($this, sanctumToken($user))->postJson('/api/v1/widgets/actions/time_tracking.start/'.$project->id);

        expectApiSuccess($response);
        $response->assertJsonPath('data', null);
        $running = runningEntries($user)->sole();
        expect($running->project_id)->toBe($project->id)
            ->and($running->description)->toBeNull()
            ->and($running->started_at->toDateTimeString())->toBe('2026-08-14 07:00:00');
    });

    it('switches from another running timer', function () {
        Carbon::setTestNow('2026-08-14 07:00:00');
        $user = widgetUser();
        $reading = widgetProject($user, 'Reading');
        $writing = widgetProject($user, 'Writing');
        $old = widgetEntry($user, $reading, '2026-08-14 06:15:00', true);

        expectApiSuccess(bearer($this, sanctumToken($user))->postJson('/api/v1/widgets/actions/time_tracking.start/'.$writing->id));

        $old->refresh();
        expect($old->stopped_at->toDateTimeString())->toBe('2026-08-14 07:00:00')
            ->and($old->duration_minutes)->toBe(45)
            ->and(runningEntries($user)->sole()->project_id)->toBe($writing->id);
    });

    it('changes nothing while the project\'s timer runs', function () {
        $user = widgetUser();
        $project = widgetProject($user, 'Writing');
        $entry = widgetEntry($user, $project, now()->subHour()->toDateTimeString(), true);

        expectApiSuccess(bearer($this, sanctumToken($user))->postJson('/api/v1/widgets/actions/time_tracking.start/'.$project->id));

        expect(runningEntries($user)->sole()->id)->toBe($entry->id)
            ->and(TimeTrackingEntry::forUser($user->id)->count())->toBe(1);
    });

    it('never leaves two running timers', function () {
        $user = widgetUser();
        $token = sanctumToken($user);
        $reading = widgetProject($user, 'Reading');
        $writing = widgetProject($user, 'Writing');

        foreach ([$reading, $reading, $writing, $reading, $writing, $writing] as $project) {
            expectApiSuccess(bearer($this, $token)->postJson('/api/v1/widgets/actions/time_tracking.start/'.$project->id));
            expect(runningEntries($user))->toHaveCount(1)
                ->and(runningEntries($user)->first()->project_id)->toBe($project->id);
        }
    });

    it('rejects another user\'s project with 403', function () {
        $project = widgetProject(widgetUser(), 'Not yours');
        $user = widgetUser();

        expectApiError(bearer($this, sanctumToken($user))->postJson('/api/v1/widgets/actions/time_tracking.start/'.$project->id), 403);
        expect(TimeTrackingEntry::count())->toBe(0);
    });

    it('answers 404 for a missing project', function () {
        expectApiError(bearer($this, sanctumToken(widgetUser()))->postJson('/api/v1/widgets/actions/time_tracking.start/'.Str::uuid()), 404);
    });
});

describe('POST /widgets/actions/time_tracking.stop/{entry}', function () {
    it('stops the running timer', function () {
        Carbon::setTestNow('2026-08-14 07:00:00');
        $user = widgetUser();
        $entry = widgetEntry($user, widgetProject($user, 'Writing'), '2026-08-14 05:00:00', true);

        $response = bearer($this, sanctumToken($user))->postJson('/api/v1/widgets/actions/time_tracking.stop/'.$entry->id);

        expectApiSuccess($response);
        $response->assertJsonPath('data', null);
        $entry->refresh();
        expect($entry->stopped_at->toDateTimeString())->toBe('2026-08-14 07:00:00')
            ->and($entry->duration_minutes)->toBe(120);
    });

    it('changes nothing when the entry is already stopped', function () {
        $user = widgetUser();
        $entry = widgetEntry($user, widgetProject($user, 'Writing'), '2026-08-13 10:00:00');
        $before = $entry->only(['stopped_at', 'duration_minutes']);

        expectApiSuccess(bearer($this, sanctumToken($user))->postJson('/api/v1/widgets/actions/time_tracking.stop/'.$entry->id));

        expect($entry->fresh()->only(['stopped_at', 'duration_minutes']))->toEqual($before);
    });

    it('rejects another user\'s entry with 403', function () {
        $owner = widgetUser();
        $entry = widgetEntry($owner, widgetProject($owner, 'Not yours'), now()->subHour()->toDateTimeString(), true);

        expectApiError(bearer($this, sanctumToken(widgetUser()))->postJson('/api/v1/widgets/actions/time_tracking.stop/'.$entry->id), 403);
        expect($entry->fresh()->stopped_at)->toBeNull();
    });

    it('answers 404 for a missing entry', function () {
        expectApiError(bearer($this, sanctumToken(widgetUser()))->postJson('/api/v1/widgets/actions/time_tracking.stop/'.Str::uuid()), 404);
    });
});

describe('Time tracking widget routes and tokens', function () {
    it('work with a widget token and with a normal token', function (string $kind) {
        $user = widgetUser();
        $token = $kind === 'widget' ? issueWidgetToken($this, sessionToken($user)) : sanctumToken($user);
        $project = widgetProject($user, 'Writing');

        timeTrackingWidget($this, $user, $token);
        expectApiSuccess(bearer($this, $token)->postJson('/api/v1/widgets/actions/time_tracking.start/'.$project->id));
        expectApiSuccess(bearer($this, $token)->postJson('/api/v1/widgets/actions/time_tracking.stop/'.runningEntries($user)->sole()->id));
        expect(runningEntries($user))->toHaveCount(0);
    })->with(['widget', 'normal']);

    it('keeps a widget token out of the time tracking routes', function () {
        $user = widgetUser();
        $token = issueWidgetToken($this, sessionToken($user));
        $project = widgetProject($user, 'Writing');

        expectApiError(bearer($this, $token)->getJson('/api/v1/time-tracking/entries'), 401);
        expectApiError(bearer($this, $token)->getJson('/api/v1/time-tracking/projects'), 401);
        expectApiError(bearer($this, $token)->postJson('/api/v1/time-tracking/entries/start', ['project_id' => $project->id]), 401);
    });
});

describe('GET /widgets/check-in', function () {
    it('returns the enabled trackers in the app order with today\'s values', function () {
        Carbon::setTestNow('2026-08-14 10:00:00');
        $user = widgetUser(['check_in_settings' => ['enabled_trackers' => ['water', 'social_life', 'mood', 'unknown'], 'selected_sports' => []]]);
        widgetCheckIn($user, '2026-08-14', ['mood' => 4]);
        widgetCheckIn($user, '2026-08-13', ['water' => 2]);

        $data = checkInWidget($this, $user);

        expect($data)->toMatchArray([
            'kind' => 'check-in',
            'schema_version' => 1,
            'refresh_at' => '2026-08-15T00:00:00+00:00',
            'labels' => ['title' => 'Check-in', 'empty' => 'No trackers enabled.'],
        ])
            ->and(array_column($data['sections'], 'key'))->toBe(['today'])
            ->and(collect($data['sections'][0]['rows'])->pluck('id')->all())->toBe(['mood', 'water', 'social_life'])
            ->and(checkInRow($data, 'mood'))->toMatchArray([
                'title' => 'Mood',
                'all_day' => true,
            ])
            ->and(checkInRow($data, 'mood'))->not->toHaveKey('route')
            ->and(checkInRow($data, 'mood')['action'])->toMatchArray(['type' => 'scale', 'key' => 'check_in.set', 'value' => 4])
            ->and(checkInRow($data, 'water')['action'])->toHaveKey('value', null);
    });

    it('uses the default trackers and sports without settings', function () {
        $user = widgetUser(['check_in_settings' => null]);

        $data = checkInWidget($this, $user);

        expect(collect(widgetSection($data, 'today')['rows'])->pluck('id')->all())
            ->toBe(['mood', 'sleep', 'dreams', 'food_quality', 'food_amount', 'water', 'sports', 'work'])
            ->and(collect(checkInRow($data, 'sports')['action']['options'])->pluck('icon')->all())
            ->toBe(['sport_dumbbell', 'sport_bike', 'sport_mountain', 'sport_footprints', 'sport_waves_ladder']);
    });

    it('sends null values when there is no check-in today', function () {
        $user = widgetUser();

        $rows = widgetSection(checkInWidget($this, $user), 'today')['rows'];

        expect(collect($rows)->pluck('action.value')->unique()->all())->toBe([null]);
    });

    it('leaves out the section when no tracker is enabled', function () {
        $user = widgetUser(['check_in_settings' => ['enabled_trackers' => [], 'selected_sports' => []]]);

        expect(checkInWidget($this, $user)['sections'])->toBe([]);
    });

    it('builds the mood, other and menstruation options with their colors', function () {
        $user = widgetUser(['check_in_settings' => ['enabled_trackers' => ['mood', 'sleep', 'menstruation']]]);

        $data = checkInWidget($this, $user);
        $colors = ['#EF4444', '#F97316', '#3B82F6', '#86EFAC', '#22C55E'];

        expect(checkInRow($data, 'mood')['action']['options'])->toBe(array_map(
            fn (int $value) => ['value' => $value, 'icon' => 'mood_'.$value, 'color' => $colors[$value - 1]],
            range(1, 5),
        ))
            ->and(checkInRow($data, 'sleep')['action']['options'])->toBe(array_map(
                fn (int $value) => ['value' => $value, 'icon' => 'sleep', 'color' => $colors[$value - 1]],
                range(1, 5),
            ))
            ->and(checkInRow($data, 'menstruation')['action']['options'])->toBe(array_map(
                fn (int $value) => ['value' => $value, 'icon' => 'menstruation', 'color' => '#FB7185'],
                range(1, 5),
            ));
    });

    it('offers the selected sports in order plus today\'s sport when it is no longer selected', function () {
        $user = widgetUser(['check_in_settings' => ['enabled_trackers' => ['sports'], 'selected_sports' => ['yoga', 'bike', 'unknown']]]);
        widgetCheckIn($user, today()->toDateString(), ['sports' => 3]);

        $action = checkInRow(checkInWidget($this, $user), 'sports')['action'];

        expect($action['value'])->toBe(3)
            ->and($action['options'])->toBe([
                ['value' => 6, 'icon' => 'sport_yoga', 'color' => '#1DBDA5'],
                ['value' => 2, 'icon' => 'sport_bike', 'color' => '#1DBDA5'],
                ['value' => 3, 'icon' => 'sport_mountain', 'color' => '#1DBDA5'],
            ]);
    });

    it('takes today from the user timezone', function (string $timezone, int $mood, string $refreshAt) {
        Carbon::setTestNow('2026-08-14 20:00:00');
        $user = widgetUser(['timezone' => $timezone, 'check_in_settings' => ['enabled_trackers' => ['mood']]]);
        widgetCheckIn($user, '2026-08-14', ['mood' => 1]);
        widgetCheckIn($user, '2026-08-15', ['mood' => 5]);

        $data = checkInWidget($this, $user);

        expect(checkInRow($data, 'mood')['action']['value'])->toBe($mood)
            ->and($data['refresh_at'])->toBe($refreshAt);
    })->with([
        'Pacific/Auckland' => ['Pacific/Auckland', 5, '2026-08-16T00:00:00+12:00'],
        'America/Los_Angeles' => ['America/Los_Angeles', 1, '2026-08-15T00:00:00-07:00'],
    ]);

    it('follows the user language', function () {
        $user = widgetUser(['language' => 'de', 'check_in_settings' => ['enabled_trackers' => ['mood']]]);

        $data = checkInWidget($this, $user);

        expect($data['labels']['title'])->toBe('Check-in')
            ->and(checkInRow($data, 'mood')['title'])->toBe('Stimmung');
    });
});

describe('POST /widgets/actions/check_in.set/{tracker}', function () {
    it('creates today\'s check-in', function () {
        Carbon::setTestNow('2026-08-14 20:00:00');
        $user = widgetUser(['timezone' => 'Pacific/Auckland']);

        expectApiSuccess(setCheckIn($this, $user, 'mood', ['value' => 4]));

        $checkIn = userCheckIns($user)->sole();
        expect($checkIn->date)->toBe('2026-08-15')
            ->and($checkIn->values['mood'])->toBe(4);
    });

    it('updates today\'s check-in and keeps the other trackers', function () {
        $user = widgetUser();
        widgetCheckIn($user, today()->toDateString(), ['mood' => 2, 'water' => 3]);

        expectApiSuccess(setCheckIn($this, $user, 'sports', ['value' => 6]));
        expectApiSuccess(setCheckIn($this, $user, 'sports', ['value' => 6]));

        expect(userCheckIns($user)->sole()->values)->toEqual(['mood' => 2, 'water' => 3, 'sports' => 6]);
    });

    it('clears a tracker with null', function () {
        $user = widgetUser();
        widgetCheckIn($user, today()->toDateString(), ['mood' => 2]);

        expectApiSuccess(setCheckIn($this, $user, 'mood', ['value' => null]));

        expect(userCheckIns($user))->toHaveCount(0);
    });

    it('creates no check-in when clearing without one', function () {
        $user = widgetUser();

        expectApiSuccess(setCheckIn($this, $user, 'mood', ['value' => null]));

        expect(userCheckIns($user))->toHaveCount(0);
    });

    it('rejects invalid values with 422', function (string $tracker, array $body) {
        $user = widgetUser();

        $response = setCheckIn($this, $user, $tracker, $body);

        expectApiError($response, 422);
        $response->assertJsonValidationErrors('value');
        expect(userCheckIns($user))->toHaveCount(0);
    })->with([
        '6 for mood' => ['mood', ['value' => 6]],
        '0 for water' => ['water', ['value' => 0]],
        '7 for sports' => ['sports', ['value' => 7]],
        'a string' => ['mood', ['value' => 'abc']],
        'no value' => ['mood', []],
    ]);

    it('answers 404 for an unknown tracker', function () {
        $user = widgetUser();

        expectApiError(setCheckIn($this, $user, 'coffee', ['value' => 3]), 404);
    });

    it('never touches another user\'s check-in', function () {
        $user = widgetUser();
        $other = widgetUser();
        widgetCheckIn($other, today()->toDateString(), ['mood' => 1]);

        expectApiSuccess(setCheckIn($this, $user, 'mood', ['value' => 5]));

        expect(userCheckIns($other)->sole()->values['mood'])->toBe(1)
            ->and(userCheckIns($user)->sole()->values['mood'])->toBe(5);
    });
});

function newAppCheckInWidget($test, User $user, string $version = '1.2.0'): array
{
    $response = bearer($test, sanctumToken($user))->withHeader('X-Client-Version', $version)->getJson('/api/v1/widgets/check-in');
    expectApiSuccess($response);

    return $response->json('data');
}

function ownWidgetTracker(User $user, CheckInTrackerType $type, string $name, int $position, bool $enabled = true): CheckInTracker
{
    return CheckInTracker::factory()->forUser($user)->ofType($type)->create(['name' => $name, 'icon' => 'droplet', 'position' => $position, 'enabled' => $enabled]);
}

function addCheckIn($test, User $user, string $tracker, mixed $body, ?string $token = null)
{
    return bearer($test, $token ?? sanctumToken($user))->postJson('/api/v1/widgets/actions/check_in.add/'.$tracker, $body);
}

function trackerValueToday(CheckInTracker $tracker): ?int
{
    return CheckInValue::where('check_in_tracker_id', $tracker->id)->whereDate('date', today())->value('value');
}

describe('Own trackers in the check-in widget', function () {
    it('gives apps before 1.2.0 the built-in trackers only, as before', function (?string $version) {
        $user = widgetUser(['check_in_settings' => ['enabled_trackers' => ['mood'], 'selected_sports' => []]]);
        ownWidgetTracker($user, CheckInTrackerType::Scale, 'Focus', 200);
        ownWidgetTracker($user, CheckInTrackerType::YesNo, 'Meditated', 201);

        $response = bearer($this, sanctumToken($user));
        if ($version !== null) {
            $response = $response->withHeader('X-Client-Version', $version);
        }
        $data = $response->getJson('/api/v1/widgets/check-in')->json('data');

        expect($data['sections'])->toBe([['key' => 'today', 'rows' => [checkInRow($data, 'mood')]]])
            ->and(checkInRow($data, 'mood'))->not->toBeNull();
    })->with([null, '1.1.4']);

    it('gives apps from 1.2.0 the ratings and the own yes/no and count trackers in the tracker order', function () {
        Carbon::setTestNow('2026-08-14 10:00:00');
        $user = widgetUser(['check_in_settings' => ['enabled_trackers' => ['mood'], 'selected_sports' => []]]);
        $focus = ownWidgetTracker($user, CheckInTrackerType::Scale, 'Focus', 200);
        $meditated = ownWidgetTracker($user, CheckInTrackerType::YesNo, 'Meditated', 201);
        $water = ownWidgetTracker($user, CheckInTrackerType::Count, 'Glasses of water', 202);
        ownWidgetTracker($user, CheckInTrackerType::Count, 'Switched off', 203, enabled: false);
        app(CheckInService::class)->setValues($user, '2026-08-14', [
            ['tracker_id' => $focus->id, 'value' => 4],
            ['tracker_id' => $water->id, 'value' => 3],
        ]);

        $data = newAppCheckInWidget($this, $user);
        $today = widgetSection($data, 'today');
        $own = widgetSection($data, 'own');

        expect(array_column($data['sections'], 'key'))->toBe(['today', 'own'])
            ->and($today['title'])->toBe('Ratings')
            ->and($own['title'])->toBe('Own trackers')
            ->and(array_column($today['rows'], 'id'))->toBe(['mood', 'own_'.$focus->id])
            ->and($today['rows'][1])->toMatchArray(['title' => 'Focus', 'icon' => 'lucide:droplet'])
            ->and($today['rows'][1]['action'])->toMatchArray(['type' => 'scale', 'key' => 'check_in.set', 'value' => 4])
            ->and(array_column($today['rows'][1]['action']['options'], 'value'))->toBe([1, 2, 3, 4, 5])
            ->and(array_column($own['rows'], 'id'))->toBe(['own_'.$meditated->id, 'own_'.$water->id])
            ->and($own['rows'][0]['action'])->toBe(['type' => 'toggle', 'key' => 'check_in.set', 'value' => null])
            ->and($own['rows'][1]['action'])->toBe(['type' => 'counter', 'key' => 'check_in.add', 'value' => 3]);
    });

    it('sets and clears an own rating and yes/no tracker', function () {
        $user = widgetUser();
        $focus = ownWidgetTracker($user, CheckInTrackerType::Scale, 'Focus', 200);
        $meditated = ownWidgetTracker($user, CheckInTrackerType::YesNo, 'Meditated', 201);

        expectApiSuccess(setCheckIn($this, $user, 'own_'.$focus->id, ['value' => 5]));
        expectApiSuccess(setCheckIn($this, $user, 'own_'.$meditated->id, ['value' => 1]));
        expect(trackerValueToday($focus))->toBe(5)->and(trackerValueToday($meditated))->toBe(1);

        expectApiSuccess(setCheckIn($this, $user, 'own_'.$meditated->id, ['value' => null]));
        expect(trackerValueToday($meditated))->toBeNull();
    });

    it('refuses a value that does not fit the tracker', function () {
        $user = widgetUser();
        $focus = ownWidgetTracker($user, CheckInTrackerType::Scale, 'Focus', 200);
        $meditated = ownWidgetTracker($user, CheckInTrackerType::YesNo, 'Meditated', 201);

        expectApiError(setCheckIn($this, $user, 'own_'.$focus->id, ['value' => 6]), 422);
        expectApiError(setCheckIn($this, $user, 'own_'.$meditated->id, ['value' => 2]), 422);
    });

    it('answers 404 for another user\'s tracker and for a built-in tracker addressed by id', function () {
        $user = widgetUser();
        $other = ownWidgetTracker(widgetUser(), CheckInTrackerType::Scale, 'Theirs', 200);
        $builtIn = CheckInTracker::forUser($user->id)->where('key', 'mood')->firstOrFail();

        expectApiError(setCheckIn($this, $user, 'own_'.$other->id, ['value' => 3]), 404);
        expectApiError(setCheckIn($this, $user, 'own_'.$builtIn->id, ['value' => 3]), 404);
        expectApiError(addCheckIn($this, $user, 'own_'.$other->id, ['value' => 1]), 404);
        expect(trackerValueToday($other))->toBeNull();
    });

    it('counts a count tracker up and down, never below 0', function () {
        $user = widgetUser();
        $water = ownWidgetTracker($user, CheckInTrackerType::Count, 'Glasses of water', 200);

        expectApiSuccess(addCheckIn($this, $user, 'own_'.$water->id, ['value' => -1]));
        expect(trackerValueToday($water))->toBeNull();

        expectApiSuccess(addCheckIn($this, $user, 'own_'.$water->id, ['value' => 1]));
        expectApiSuccess(addCheckIn($this, $user, 'own_'.$water->id, ['value' => 1]));
        expect(trackerValueToday($water))->toBe(2);

        expectApiSuccess(addCheckIn($this, $user, 'own_'.$water->id, ['value' => -1]));
        expectApiSuccess(addCheckIn($this, $user, 'own_'.$water->id, ['value' => -1]));
        expectApiSuccess(addCheckIn($this, $user, 'own_'.$water->id, ['value' => -1]));
        expect(trackerValueToday($water))->toBe(0);
    });

    it('only counts count trackers, by one', function () {
        $user = widgetUser();
        $meditated = ownWidgetTracker($user, CheckInTrackerType::YesNo, 'Meditated', 200);
        $water = ownWidgetTracker($user, CheckInTrackerType::Count, 'Glasses of water', 201);

        expectApiError(addCheckIn($this, $user, 'own_'.$meditated->id, ['value' => 1]), 422);
        expectApiError(addCheckIn($this, $user, 'own_'.$water->id, ['value' => 2]), 422);
        expect(trackerValueToday($meditated))->toBeNull()->and(trackerValueToday($water))->toBeNull();
    });

    it('works with a widget token', function () {
        $user = widgetUser();
        $water = ownWidgetTracker($user, CheckInTrackerType::Count, 'Glasses of water', 200);
        $token = issueWidgetToken($this, sessionToken($user));

        expectApiSuccess(addCheckIn($this, $user, 'own_'.$water->id, ['value' => 1], $token));
        expect(trackerValueToday($water))->toBe(1);
    });
});

describe('Check-in widget routes and tokens', function () {
    it('work with a widget token and with a normal token', function (string $kind) {
        $user = widgetUser();
        $token = $kind === 'widget' ? issueWidgetToken($this, sessionToken($user)) : sanctumToken($user);

        expectApiSuccess(setCheckIn($this, $user, 'mood', ['value' => 3], $token));
        expect(checkInRow(checkInWidget($this, $user, $token), 'mood')['action']['value'])->toBe(3);
    })->with(['widget', 'normal']);

    it('keeps a widget token out of the check-in routes', function () {
        $user = widgetUser();
        $token = issueWidgetToken($this, sessionToken($user));

        expectApiError(bearer($this, $token)->getJson('/api/v1/check-in'), 401);
        expectApiError(bearer($this, $token)->postJson('/api/v1/check-in', ['date' => today()->toDateString(), 'mood' => 3]), 401);
    });
});
