<?php

use App\Api\Calendars\Services\CalendarService;
use App\Dav\DTOs\EventDTO;
use App\Dav\Services\DavService;
use Illuminate\Support\Carbon;

/*
 * Every listing is read once before the write, so each assertion after it
 * only holds if the write invalidated the cached listing.
 */
beforeEach(function () {
    Carbon::setTestNow('2030-10-10 08:00:00');

    $this->user = makeUser();
    $this->user->settings()->update(['timezone' => 'UTC']);
    $this->instanceId = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data.0.id');

    $this->storeEvent = fn (int $instanceId, string $title, array $fields = []) => $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/calendars/'.$instanceId.'/events', array_merge([
            'title' => $title,
            'start_date' => '2030-10-10 15:00:00',
            'end_date' => '2030-10-10 16:00:00',
            'is_all_day' => false,
            'calendar_id' => $instanceId,
        ], $fields))
        ->assertStatus(201)
        ->json('data');

    $this->listings = fn () => [
        'month' => collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars/events/2030-10')->assertStatus(200)->json('data')),
        'widget' => collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars/events/widget')->assertStatus(200)->json('data')),
        'today' => collect(app(CalendarService::class)->listTodayEvents($this->user))
            ->map(fn (EventDTO $event) => ['title' => $event->title, 'start_date' => $event->startDate?->format('Y-m-d\TH:i:s')]),
    ];
    $this->titles = fn () => array_map(fn ($events) => $events->pluck('title')->sort()->values()->all(), ($this->listings)());

    // An empty listing isn't cached, so every calendar starts with one event.
    $this->seed = ($this->storeEvent)($this->instanceId, 'Seed');
    expect(($this->titles)())->toBe(['month' => ['Seed'], 'widget' => ['Seed'], 'today' => ['Seed']]);
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('Calendar event cache: API writes', function () {
    it('serves an unchanged listing from the cache', function () {
        $dav = app(DavService::class);
        $calendar = $dav->calendars()->get($this->user, $this->instanceId);
        $dav->calendars()->events()->create($calendar, EventDTO::fromRequest([
            'title' => 'Behind the cache',
            'start_date' => '2030-10-10 17:00:00',
            'end_date' => '2030-10-10 18:00:00',
            'is_all_day' => false,
        ], $calendar, $this->user));

        expect(($this->titles)())->toBe(['month' => ['Seed'], 'widget' => ['Seed'], 'today' => ['Seed']]);

        ($this->storeEvent)($this->instanceId, 'Written');

        $all = ['Behind the cache', 'Seed', 'Written'];
        expect(($this->titles)())->toBe(['month' => $all, 'widget' => $all, 'today' => $all]);
    });

    it('shows a created event', function () {
        ($this->storeEvent)($this->instanceId, 'Created');

        expect(($this->titles)())->toBe(['month' => ['Created', 'Seed'], 'widget' => ['Created', 'Seed'], 'today' => ['Created', 'Seed']]);
    });

    it('shows an updated event', function () {
        $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/calendars/'.$this->instanceId.'/events/'.$this->seed['uri'], [
            'title' => 'Renamed',
            'start_date' => '2030-10-10 15:00:00',
            'end_date' => '2030-10-10 16:00:00',
            'is_all_day' => false,
            'calendar_id' => $this->instanceId,
            'etag' => $this->seed['etag'],
        ])->assertStatus(200);

        expect(($this->titles)())->toBe(['month' => ['Renamed'], 'widget' => ['Renamed'], 'today' => ['Renamed']]);
    });

    it('drops a deleted event', function () {
        ($this->storeEvent)($this->instanceId, 'Kept');
        expect(($this->titles)()['month'])->toBe(['Kept', 'Seed']);

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson('/api/v1/calendars/'.$this->instanceId.'/events/'.$this->seed['uri'])
            ->assertStatus(200);

        expect(($this->titles)())->toBe(['month' => ['Kept'], 'widget' => ['Kept'], 'today' => ['Kept']]);
    });

    it('shows a moved event in the target calendar only', function () {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/calendars', ['name' => 'Work'])->assertStatus(201);
        $work = collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data'))->firstWhere('name', 'Work')['id'];
        ($this->storeEvent)($work, 'Work seed');
        $moving = ($this->storeEvent)($this->instanceId, 'Moving');
        expect(($this->titles)()['month'])->toBe(['Moving', 'Seed', 'Work seed']);

        $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/calendars/'.$this->instanceId.'/events/'.$moving['uri'], [
            'title' => 'Moved',
            'start_date' => '2030-10-10 15:00:00',
            'end_date' => '2030-10-10 16:00:00',
            'is_all_day' => false,
            'calendar_id' => $work,
            'etag' => $moving['etag'],
        ])->assertStatus(200);

        $listings = ($this->listings)();
        foreach (['month', 'widget', 'today'] as $listing) {
            expect($listings[$listing]->pluck('title')->sort()->values()->all())->toBe(['Moved', 'Seed', 'Work seed']);
        }
        expect((int) $listings['month']->firstWhere('title', 'Moved')['calendar_id'])->toBe($work)
            ->and((int) $listings['widget']->firstWhere('title', 'Moved')['calendar_id'])->toBe($work);
    });

    it('shows an edited and a deleted occurrence of a series', function () {
        $series = ($this->storeEvent)($this->instanceId, 'Series', ['is_recurring' => true, 'recurrence_rule' => 'FREQ=DAILY;COUNT=3']);
        $seriesStarts = fn () => array_map(
            fn ($events) => $events->where('title', '!=', 'Seed')->map(fn ($e) => $e['title'].' '.$e['start_date'])->sort()->values()->all(),
            ($this->listings)()
        );
        expect($seriesStarts()['month'])->toBe(['Series 2030-10-10T15:00:00', 'Series 2030-10-11T15:00:00', 'Series 2030-10-12T15:00:00']);

        $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/calendars/'.$this->instanceId.'/events/'.$series['uri'].'/occurrence/'.rawurlencode('2030-10-10T15:00:00.000Z'), [
            'title' => 'Changed',
            'start_date' => '2030-10-10 17:00:00',
            'end_date' => '2030-10-10 18:00:00',
            'is_all_day' => false,
            'calendar_id' => $this->instanceId,
            'etag' => $series['etag'],
        ])->assertStatus(200);

        expect($seriesStarts())->toBe([
            'month' => ['Changed 2030-10-10T17:00:00', 'Series 2030-10-11T15:00:00', 'Series 2030-10-12T15:00:00'],
            'widget' => ['Changed 2030-10-10T17:00:00', 'Series 2030-10-11T15:00:00', 'Series 2030-10-12T15:00:00'],
            'today' => ['Changed 2030-10-10T17:00:00'],
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson('/api/v1/calendars/'.$this->instanceId.'/events/'.$series['uri'].'/occurrence/'.rawurlencode('2030-10-10T15:00:00.000Z'))
            ->assertStatus(200);

        expect($seriesStarts())->toBe([
            'month' => ['Series 2030-10-11T15:00:00', 'Series 2030-10-12T15:00:00'],
            'widget' => ['Series 2030-10-11T15:00:00', 'Series 2030-10-12T15:00:00'],
            'today' => [],
        ]);
    });

    it('shows a recoloured calendar in its cached events', function () {
        $colours = fn () => array_map(fn ($events) => $events->pluck('calendar_color')->all(), array_intersect_key(($this->listings)(), ['month' => 0, 'widget' => 0]));
        expect($colours()['month'])->not->toBe(['#123456']);

        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/v1/calendars/'.$this->instanceId, ['color' => '#123456'])
            ->assertStatus(200);

        expect($colours())->toBe(['month' => ['#123456'], 'widget' => ['#123456']]);
    });

    it('drops the events of a deleted calendar', function () {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/calendars', ['name' => 'Work'])->assertStatus(201);
        $work = collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data'))->firstWhere('name', 'Work')['id'];
        ($this->storeEvent)($work, 'Work event');
        expect(($this->titles)()['month'])->toBe(['Seed', 'Work event']);

        $this->actingAs($this->user, 'sanctum')->deleteJson('/api/v1/calendars/'.$work)->assertStatus(200);

        expect(($this->titles)())->toBe(['month' => ['Seed'], 'widget' => ['Seed'], 'today' => ['Seed']]);
    });
});
