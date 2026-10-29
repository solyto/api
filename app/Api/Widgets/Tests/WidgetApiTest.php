<?php

use App\Api\Todos\Models\Todo;
use App\Api\Users\Models\User;
use Carbon\Carbon;

function widgetUser(array $settings = []): User
{
    $user = makeUser();
    $user->settings()->update(array_merge([
        'timezone' => 'UTC',
        'language' => 'en',
        'date_format' => 'dd.mm.YYYY',
        'time_format' => 'H:i',
    ], $settings));

    return $user->fresh();
}

function widgetEvent($test, User $user, array $payload): void
{
    $instanceId = $test->actingAs($user, 'sanctum')->getJson('/api/v1/calendars')->json('data')[0]['id'];

    $test->actingAs($user, 'sanctum')
        ->postJson('/api/v1/calendars/'.$instanceId.'/events', array_merge(['is_all_day' => false], $payload, ['calendar_id' => $instanceId]))
        ->assertStatus(201);
}

function widgetTodo(User $user, string $title, ?string $dueAt, bool $completed = false, array $attributes = []): Todo
{
    return Todo::factory()->forUser($user)->create(array_merge([
        'title' => $title,
        'due_at' => $dueAt,
        'is_completed' => $completed,
    ], $attributes));
}

/**
 * Each request must resolve its own bearer token; the guard caches the first user otherwise.
 */
function widgetRequest($test, string $token)
{
    app('auth')->forgetGuards();

    return $test->withHeader('Authorization', 'Bearer '.$token);
}

function widgetTokenFor($test, User $user): string
{
    $sessionToken = $user->createToken('auth-token-mobile', expiresAt: now()->addDays(30))->plainTextToken;

    return widgetRequest($test, $sessionToken)->postJson('/api/v1/auth/widget-token')->assertStatus(201)->json('data.token');
}

function yourDay($test, User $user): array
{
    $response = widgetRequest($test, sanctumToken($user))->getJson('/api/v1/widgets/your-day');
    expectApiSuccess($response);

    return $response->json('data');
}

function widgetSection(array $data, string $key): ?array
{
    return collect($data['sections'])->firstWhere('key', $key);
}

afterEach(function () {
    Carbon::setTestNow();
});

describe('GET /widgets/your-day', function () {
    it('returns today, due and upcoming in that order with the right rows', function () {
        Carbon::setTestNow('2026-08-14 07:00:00');
        $user = widgetUser();

        widgetEvent($this, $user, ['title' => 'Dentist', 'start_date' => '2026-08-14 09:30', 'end_date' => '2026-08-14 10:30']);
        widgetEvent($this, $user, ['title' => 'Holiday', 'start_date' => '2026-08-14', 'end_date' => '2026-08-14', 'is_all_day' => true]);
        widgetEvent($this, $user, ['title' => 'Meeting', 'start_date' => '2026-08-15 09:00', 'end_date' => '2026-08-15 10:00']);
        widgetEvent($this, $user, ['title' => 'Trip', 'start_date' => '2026-08-15', 'end_date' => '2026-08-16', 'is_all_day' => true]);
        widgetEvent($this, $user, ['title' => 'Far away', 'start_date' => '2026-08-18 09:00', 'end_date' => '2026-08-18 10:00']);

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
            'route' => ['key' => 'todo', 'id' => $overdue->id],
            'action' => ['type' => 'check', 'key' => 'todo.complete', 'checked' => false],
        ])->and(widgetSection($data, 'due')['rows'][1])->not->toHaveKey('subtitle');

        $upcoming = widgetSection($data, 'upcoming')['rows'];
        expect($upcoming[0]['time_label'])->toBe('15.08')
            ->and($upcoming[1]['time_label'])->toBe('15.08 09:00')
            ->and($upcoming[1]['route'])->toBe(['key' => 'calendar']);
    });

    it('sends all-day events and todos as dates and timed events as timestamps', function () {
        Carbon::setTestNow('2026-08-14 07:00:00');
        $user = widgetUser(['timezone' => 'Europe/Berlin']);

        widgetEvent($this, $user, ['title' => 'Dentist', 'start_date' => '2026-08-14 09:30', 'end_date' => '2026-08-14 10:30']);
        widgetEvent($this, $user, ['title' => 'Holiday', 'start_date' => '2026-08-14', 'end_date' => '2026-08-14', 'is_all_day' => true]);

        [$holiday, $dentist] = widgetSection(yourDay($this, $user), 'today')['rows'];

        expect($holiday)->toMatchArray(['at' => '2026-08-14', 'until' => '2026-08-14', 'all_day' => true, 'icon' => 'event', 'time_label' => 'all day'])
            ->and($dentist)->toMatchArray(['at' => '2026-08-14T09:30:00+02:00', 'until' => '2026-08-14T10:30:00+02:00', 'all_day' => false, 'time_label' => '09:30']);
    });

    it('expands recurring events', function () {
        Carbon::setTestNow('2026-08-14 07:00:00');
        $user = widgetUser();

        widgetEvent($this, $user, ['title' => 'Standup', 'start_date' => '2026-08-10 08:00', 'end_date' => '2026-08-10 08:15', 'is_recurring' => true, 'recurrence_rule' => 'FREQ=DAILY']);

        $data = yourDay($this, $user);

        expect(collect(widgetSection($data, 'today')['rows'])->pluck('at')->all())->toBe(['2026-08-14T08:00:00+00:00'])
            ->and(collect(widgetSection($data, 'upcoming')['rows'])->pluck('id')->unique())->toHaveCount(count(widgetSection($data, 'upcoming')['rows']));
    });

    it('takes today from the user timezone', function (string $timezone, array $expected) {
        Carbon::setTestNow('2026-08-14 20:00:00');
        $user = widgetUser(['timezone' => $timezone]);

        widgetEvent($this, $user, ['title' => 'Breakfast', 'start_date' => '2026-08-15 09:00', 'end_date' => '2026-08-15 10:00']);
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

        widgetEvent($this, $user, ['title' => 'Dentist', 'start_date' => '2026-08-14 09:30', 'end_date' => '2026-08-14 10:30']);
        widgetEvent($this, $user, ['title' => 'Dinner', 'start_date' => '2026-08-15 19:00', 'end_date' => '2026-08-15 21:00']);
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

        $response = widgetRequest($this, sanctumToken($user))->postJson('/api/v1/widgets/actions/todo.complete/'.$todo->id);

        expectApiSuccess($response);
        $response->assertJsonPath('data', null);
        expect($todo->fresh()->is_completed)->toBeTrue()
            ->and($todo->fresh()->completed_at)->not->toBeNull();
    });

    it('rejects another user\'s todo with 403', function () {
        $todo = widgetTodo(widgetUser(), 'Not yours', today()->toDateString());

        expectApiError(widgetRequest($this, sanctumToken(widgetUser()))->postJson('/api/v1/widgets/actions/todo.complete/'.$todo->id), 403);
        expect($todo->fresh()->is_completed)->toBeFalse();
    });

    it('answers 404 for a missing todo', function () {
        expectApiError(widgetRequest($this, sanctumToken(widgetUser()))->postJson('/api/v1/widgets/actions/todo.complete/'.Str::uuid()), 404);
    });

    it('changes nothing when the todo is already completed', function () {
        $user = widgetUser();
        $completedAt = now()->subDay()->startOfSecond();
        $todo = widgetTodo($user, 'Done', today()->toDateString(), true, [
            'completed_at' => $completedAt,
            'recurrence_frequency' => 'daily',
            'recurrence_interval' => 1,
        ]);

        expectApiSuccess(widgetRequest($this, sanctumToken($user))->postJson('/api/v1/widgets/actions/todo.complete/'.$todo->id));

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

        expectApiSuccess(widgetRequest($this, sanctumToken($user))->postJson('/api/v1/widgets/actions/todo.complete/'.$todo->id));

        $next = Todo::forUser($user->id)->where('is_completed', false)->sole();
        expect($next->title)->toBe('Water plants')
            ->and($next->due_at->toDateString())->toBe('2026-08-21');
    });
});

describe('Widget routes and tokens', function () {
    it('work with a widget token and with a normal token', function (string $kind) {
        $user = widgetUser();
        $token = $kind === 'widget' ? widgetTokenFor($this, $user) : sanctumToken($user);
        $todo = widgetTodo($user, 'Call mom', today()->toDateString());

        expectApiSuccess(widgetRequest($this, $token)->getJson('/api/v1/widgets/your-day'));
        expectApiSuccess(widgetRequest($this, $token)->postJson('/api/v1/widgets/actions/todo.complete/'.$todo->id));
    })->with(['widget', 'normal']);

    it('keeps a widget token out of non-widget routes', function () {
        $user = widgetUser();
        $token = widgetTokenFor($this, $user);
        $todo = widgetTodo($user, 'Call mom', today()->toDateString());

        expectApiError(widgetRequest($this, $token)->getJson('/api/v1/todos'), 401);
        expectApiError(widgetRequest($this, $token)->putJson('/api/v1/todos/'.$todo->id, ['is_completed' => true]), 401);
        expectApiError(widgetRequest($this, $token)->getJson('/api/v1/calendars/events/widget'), 401);
    });

    it('rejects requests without a token', function () {
        expectApiError($this->getJson('/api/v1/widgets/your-day'), 401);
    });
});
