<?php

use App\Api\Feeds\Models\Feed;
use App\Api\Feeds\Services\FeedReader;
use App\Api\Feeds\Services\FeedService;
use App\Dav\Services\RemoteCalDavClient;
use App\Shared\Pulse\Cards\ClientVersions;
use App\Shared\Pulse\Cards\DavRequests;
use App\Shared\Pulse\Cards\ExternalCalendars;
use App\Shared\Pulse\Cards\FeedSync;
use App\Shared\Pulse\Cards\Notifications;
use App\Shared\Pulse\Cards\QueueLoad;
use App\Shared\Pulse\Cards\RateLimits;
use App\Shared\Pulse\Cards\ReleaseLookups;
use App\Shared\Pulse\PulseMetrics;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Pulse\Facades\Pulse;
use Livewire\Livewire;
use Tests\Support\PulseMetricsTestJob;
use Tests\Support\PulseMetricsTestNotification;

uses(RefreshDatabase::class);

covers(PulseMetrics::class);

// Pulse is disabled in tests (PULSE_ENABLED=false), which stops recording; these tests switch it back on and ingest by hand.
beforeEach(function () {
    Pulse::startRecording();
});

/** @return \Illuminate\Support\Collection<int, object{key: string, value: ?int}> */
function pulseEntries(string $type): \Illuminate\Support\Collection
{
    Pulse::ingest();

    return DB::table('pulse_entries')->where('type', $type)->get(['key', 'value']);
}

describe('PulseMetrics', function () {
    it('records a feed sync with its og:image lookups', function () {
        $feed = Feed::factory()->create(['url' => 'https://example.com/feed.xml']);

        $item = Mockery::mock(\SimplePie\Item::class);
        $item->shouldReceive('get_link')->andReturn('https://example.com/post-1');
        $item->shouldReceive('get_id')->andReturn('https://example.com/post-1');
        $item->shouldReceive('get_title')->andReturn('A post');
        $item->shouldReceive('get_date')->andReturn('1756730400');
        $item->shouldReceive('get_description')->andReturn('Text');

        $reader = $this->mock(FeedReader::class);
        $reader->shouldReceive('getItems')->once()->andReturn([$item]);
        // Stands in for the real lookup, which reports itself the same way.
        $reader->shouldReceive('extractImageUrl')->andReturnUsing(function () {
            PulseMetrics::countOgImageLookup(40);

            return null;
        });

        expect(app(FeedService::class)->syncFeed($feed->id))->toBeTrue();

        expect(pulseEntries(PulseMetrics::FEED_SYNC)->pluck('key')->all())->toBe(['https://example.com/feed.xml'])
            ->and(pulseEntries(PulseMetrics::FEED_OG_LOOKUPS)->sole()->value)->toBe(1)
            ->and(pulseEntries(PulseMetrics::FEED_OG_LOOKUP_MS)->sole()->value)->toBe(40)
            ->and(pulseEntries(PulseMetrics::FEED_SYNC_FAILED))->toBeEmpty();
    });

    it('records a feed sync without items as failed', function () {
        $feed = Feed::factory()->create(['url' => 'https://example.com/empty.xml']);
        $this->mock(FeedReader::class)->shouldReceive('getItems')->once()->andReturn(false);

        expect(app(FeedService::class)->syncFeed($feed->id))->toBeFalse();

        expect(pulseEntries(PulseMetrics::FEED_SYNC_FAILED)->pluck('key')->all())->toBe(['https://example.com/empty.xml']);
    });

    it('records remote CalDAV calls per host and their failures', function () {
        Http::fake([
            'cal.example.com/*' => Http::response('', 401),
            'other.example.com/*' => Http::response('<multistatus/>', 207),
        ]);
        $client = app(RemoteCalDavClient::class);

        $client->send('user', 'secret', 'PROPFIND', 'https://cal.example.com/dav/');
        $client->send('user', 'secret', 'PROPFIND', 'https://other.example.com/dav/');

        expect(pulseEntries(PulseMetrics::EXTERNAL_CALENDAR_CALL)->pluck('key')->sort()->values()->all())
            ->toBe(['cal.example.com', 'other.example.com'])
            ->and(pulseEntries(PulseMetrics::EXTERNAL_CALENDAR_FAILURE)->pluck('key')->all())->toBe(['cal.example.com · http 401']);
    });

    it('records an unreachable CalDAV server', function () {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        expect(fn () => app(RemoteCalDavClient::class)->send('user', 'secret', 'PROPFIND', 'https://down.example.com/dav/'))
            ->toThrow(\App\Dav\Exceptions\ExternalCalendarUnavailableException::class);

        expect(pulseEntries(PulseMetrics::EXTERNAL_CALENDAR_FAILURE)->pluck('key')->all())->toBe(['down.example.com · unreachable']);
    });

    it('names DAV clients from their user agent', function (?string $userAgent, string $client) {
        expect(PulseMetrics::davClient($userAgent))->toBe($client);
    })->with([
        ['DAVx5/4.4.2-ose (2024/09/12; dav4jvm; okhttp/4.12.0) Android/14', 'DAVx5'],
        ['Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Thunderbird/128.5.0', 'Thunderbird'],
        ['iOS/17.5 (21F79) dataaccessd/1.0', 'iOS'],
        ['macOS/14.5 (23F79) CalendarAgent/1000', 'macOS'],
        ['Evolution/3.52.1', 'Evolution'],
        ['curl/8.5.0', 'Other'],
        [null, 'Unknown'],
    ]);

    it('records feed URLs without credentials, query or fragment', function (string $url, string $key) {
        expect(PulseMetrics::feedKey($url))->toBe($key);
    })->with([
        'plain' => ['https://example.com/feed.xml', 'https://example.com/feed.xml'],
        'token in query' => ['https://example.com/feed.xml?token=secret#top', 'https://example.com/feed.xml'],
        'user info and port' => ['https://user:pass@example.com:8443/rss', 'https://example.com:8443/rss'],
        'not a URL' => ['not a url', 'unknown'],
    ]);

    it('records a private feed without its token', function () {
        PulseMetrics::feedSync('https://user:pass@example.com/feed.xml?key=secret', 100, 0, 0, false);

        expect(pulseEntries(PulseMetrics::FEED_SYNC)->pluck('key')->all())->toBe(['https://example.com/feed.xml']);
    });

    it('records DAV requests per method and client', function () {
        PulseMetrics::davRequest('propfind', 'DAVx5/4.4.2', 12);

        expect(pulseEntries(PulseMetrics::DAV_REQUEST)->pluck('key')->all())->toBe(['PROPFIND · DAVx5']);
    });

    it('records sent and failed notifications per channel and type', function () {
        $user = makeUser();

        event(new NotificationSent($user, new PulseMetricsTestNotification, 'mail'));
        event(new NotificationFailed($user, new PulseMetricsTestNotification, \NotificationChannels\WebPush\WebPushChannel::class));

        expect(pulseEntries(PulseMetrics::NOTIFICATION_SENT)->pluck('key')->all())->toBe(['mail · PulseMetricsTest'])
            ->and(pulseEntries(PulseMetrics::NOTIFICATION_FAILED)->pluck('key')->all())->toBe(['WebPush · PulseMetricsTest']);
    });

    it('records requests refused because the client is too old', function () {
        config(['app.min_client_version' => '1.0.10']);

        expectApiError($this->actingAs(makeUser(), 'sanctum')
            ->withHeaders(['X-Client-Version' => '1.0.9'])
            ->getJson('/api/v1/users/me'), 426);

        expect(pulseEntries(PulseMetrics::CLIENT_REJECTED)->pluck('key')->all())->toBe(['1.0.9']);
    });

    it('records rate limited requests per limiter and route', function () {
        Route::middleware(['api', 'throttle:1,1'])->get('/api/v1/pulse-metrics-test', fn () => 'ok');

        $this->getJson('/api/v1/pulse-metrics-test')->assertOk();
        expectApiError($this->getJson('/api/v1/pulse-metrics-test'), 429);

        expect(pulseEntries(PulseMetrics::RATE_LIMITED)->pluck('key')->all())->toBe(['1 · GET /api/v1/pulse-metrics-test']);
    });

    it('records release lookups and returns their result', function () {
        $result = PulseMetrics::timedReleaseLookup('books', 'request', fn () => ['a release']);

        expect($result)->toBe(['a release'])
            ->and(pulseEntries(PulseMetrics::RELEASE_LOOKUP)->pluck('key')->all())->toBe(['books · request']);
    });

    it('records how long a job waited before it started', function () {
        config(['queue.default' => 'sync']);

        PulseMetricsTestJob::dispatch();

        $entry = pulseEntries(PulseMetrics::QUEUE_WAIT)->sole();
        expect($entry->key)->toEndWith(' · PulseMetricsTestJob')
            ->and($entry->value)->toBeGreaterThanOrEqual(0);
    });

    it('computes when a delayed job may start', function () {
        $now = time();

        expect(PulseMetrics::availableAt(null))->toBeBetween($now, $now + 1)
            ->and(PulseMetrics::availableAt(10))->toBeBetween($now + 10, $now + 11)
            ->and(PulseMetrics::availableAt(new DateInterval('PT1M')))->toBeBetween($now + 60, $now + 61)
            ->and(PulseMetrics::availableAt(new DateTimeImmutable('@'.($now + 300))))->toBe($now + 300);
    });

    it('records the queue size as an aggregate and as the latest value', function () {
        PulseMetrics::queueSize('default', 7);

        expect(pulseEntries(PulseMetrics::QUEUE_SIZE)->sole()->value)->toBe(7)
            ->and(DB::table('pulse_values')->where('type', PulseMetrics::QUEUE_SIZE)->value('value'))->toBe('7');
    });

    it('samples the size of the default queue every minute from the schedule', function () {
        config(['queue.default' => 'redis', 'queue.connections.redis.queue' => 'jobs']);
        Queue::fake();
        Queue::pushOn('jobs', new PulseMetricsTestJob());
        Queue::pushOn('jobs', new PulseMetricsTestJob());
        Queue::pushOn('other', new PulseMetricsTestJob());

        $event = collect(app(Schedule::class)->events())->firstWhere('description', 'pulse-queue-size');
        $event->run(app());

        expect($event->expression)->toBe('* * * * *')
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and(pulseEntries(PulseMetrics::QUEUE_SIZE)->map(fn ($entry) => [$entry->key, $entry->value])->all())->toBe([['jobs', 2]]);
    });
});

describe('Pulse cards', function () {
    it('render with recorded data', function (string $card, string $expected) {
        PulseMetrics::feedSync('https://example.com/feed.xml', 1200, 3, 900, true);
        PulseMetrics::externalCalendarCall('https://cal.example.com/dav/', 300, 'unreachable');
        PulseMetrics::davRequest('REPORT', 'DAVx5/4.4', 80);
        PulseMetrics::notification('mail', PulseMetricsTestNotification::class, false);
        PulseMetrics::rateLimited('clipboard-files · POST /api/v1/clipboard/file');
        PulseMetrics::releaseLookup('music', 'job', 2500);
        PulseMetrics::queueWait('default', PulseMetricsTestJob::class, 4);
        PulseMetrics::queueSize('default', 12);
        PulseMetrics::clientRejected('1.0.9');
        Pulse::ingest();

        Livewire::withoutLazyLoading()->test($card)->assertOk()->assertSee($expected, false);
    })->with([
        [FeedSync::class, 'https://example.com/feed.xml'],
        [ExternalCalendars::class, 'cal.example.com · unreachable'],
        [DavRequests::class, 'REPORT · DAVx5'],
        [Notifications::class, 'mail · PulseMetricsTest'],
        [RateLimits::class, 'clipboard-files · POST /api/v1/clipboard/file'],
        [ReleaseLookups::class, 'music · job'],
        [QueueLoad::class, 'default · PulseMetricsTestJob'],
        [ClientVersions::class, '1.0.9'],
    ]);

    it('render without data', function (string $card) {
        Livewire::withoutLazyLoading()->test($card)->assertOk();
    })->with([
        FeedSync::class,
        ExternalCalendars::class,
        DavRequests::class,
        Notifications::class,
        RateLimits::class,
        ReleaseLookups::class,
        QueueLoad::class,
        ClientVersions::class,
    ]);

    it('show active devices per platform and version', function () {
        $user = makeUser();
        $token = $user->createToken('auth-token-web')->accessToken;
        $token->forceFill(['last_used_at' => now(), 'client_version' => '1.1.3'])->save();

        Livewire::withoutLazyLoading()->test(ClientVersions::class)->assertSee('web · 1.1.3', false);
    });
});
