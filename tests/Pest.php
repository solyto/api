<?php

use App\Api\Calendars\Models\ExternalCalendar;
use App\Api\Calendars\Models\ExternalCalendarAccount;
use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\Users\Models\User;
use App\Dav\DTOs\CalendarDTO;
use App\Dav\Factories\DavServerFactory;
use App\Dav\Services\DavService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\McpServiceProvider;
use Sabre\HTTP\Request as SabreRequest;
use Sabre\HTTP\Response as SabreResponse;
use Sabre\HTTP\ResponseInterface as SabreResponseInterface;
use Sabre\HTTP\Sapi;
use Tests\Support\FakeCalDavServer;
use Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit', '../app/Api', '../app/Bots/Tests', '../app/Dav/Tests', '../app/Plugins', '../app/Mcp/Tests');
uses(RefreshDatabase::class)->in('../app/Api', '../app/Plugins', '../app/Mcp/Tests');

expect()->extend('toBeEmail', function () {
    return $this->toBeString()
        ->when(filter_var($this->value, FILTER_VALIDATE_EMAIL) === false, function ($expectation) {
            throw new \Exception(sprintf(
                'Failed asserting that [%s] is a valid email address.',
                is_scalar($this->value) ? (string) $this->value : gettype($this->value),
            ));
        });
});

// Verified, so routes that require a verified email can be called directly.
function makeUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'email_verified_at' => now(),
    ], $attributes));
}

function sanctumToken(User $user): string
{
    return $user->createToken('api-test')->plainTextToken;
}

function authHeaders(User $user): array
{
    return ['Authorization' => 'Bearer '.sanctumToken($user)];
}

// A session token with an expiry, like login issues.
function sessionToken(User $user, ?DateTimeInterface $expiresAt = null): string
{
    return $user->createToken('auth-token-mobile', expiresAt: $expiresAt ?? now()->addDays(30))->plainTextToken;
}

// A new user's web token without expiry: [plain-text token, its PersonalAccessToken].
function webToken(): array
{
    $newToken = makeUser()->createToken('auth-token-web');

    return [$newToken->plainTextToken, $newToken->accessToken];
}

// The guard caches the first user it resolves; forgetting it lets each request resolve its own token.
function bearer($test, string $token)
{
    app('auth')->forgetGuards();

    return $test->withHeader('Authorization', 'Bearer '.$token);
}

// Issued through the API, as the app does for a session.
function issueWidgetToken($test, string $parentToken): string
{
    return bearer($test, $parentToken)->postJson('/api/v1/auth/widget-token')
        ->assertStatus(201)
        ->json('data.token');
}

function expectApiSuccess($response, int $status = 200): void
{
    $response->assertStatus($status);
    $response->assertJsonPath('success', true);
}

function expectApiError($response, int $status): void
{
    $response->assertStatus($status);
    $response->assertJsonPath('success', false);
}

// DAV protocol helpers. DavController and public/dav.php read the raw PHP request, which Laravel's HTTP test
// helpers cannot reach, so these drive the server both entry points build (DavServerFactory) with Sabre objects
// and capture the response. It runs on the pgsql connection's PDO, so the per-test transaction still isolates it.

// The UserObserver creates the default calendar and address book, but not the principal.
function davUser(array $attributes = []): User
{
    $user = makeUser($attributes);
    app(DavService::class)->principals()->create($user->email);

    return $user;
}

// Basic auth as $user with the factory password; a null user sends no credentials.
function davRequest(?User $user, string $method, string $path, ?string $body = null, array $headers = [], string $password = 'password'): SabreResponse
{
    $server = app(DavServerFactory::class)->createServer();
    $server->sapi = new class extends Sapi
    {
        public static function sendResponse(SabreResponseInterface $response): void {}
    };

    if ($user !== null) {
        $headers['Authorization'] = 'Basic '.base64_encode($user->email.':'.$password);
    }
    if ($body !== null && ! isset($headers['Content-Type'])) {
        $headers['Content-Type'] = str_starts_with(ltrim($body), '<') ? 'application/xml; charset=utf-8' : 'text/plain';
    }

    $server->httpRequest = new SabreRequest($method, '/'.ltrim($path, '/'), $headers, $body);
    $server->httpResponse = new SabreResponse();
    $server->exec();

    return $server->httpResponse;
}

function davXpath(SabreResponse $response): DOMXPath
{
    $dom = new DOMDocument();
    $dom->loadXML($response->getBodyAsString());
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('d', 'DAV:');
    $xpath->registerNamespace('cal', 'urn:ietf:params:xml:ns:caldav');
    $xpath->registerNamespace('card', 'urn:ietf:params:xml:ns:carddav');
    $xpath->registerNamespace('cs', 'http://calendarserver.org/ns/');
    $xpath->registerNamespace('apple', 'http://apple.com/ns/ical/');
    $xpath->registerNamespace('s', 'http://sabredav.org/ns');

    return $xpath;
}

function davPropfindBody(array $properties): string
{
    $props = implode('', array_map(fn (string $p) => "<{$p}/>", $properties));

    return '<?xml version="1.0" encoding="utf-8"?>'
        .'<d:propfind xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:card="urn:ietf:params:xml:ns:carddav"'
        .' xmlns:cs="http://calendarserver.org/ns/" xmlns:apple="http://apple.com/ns/ical/" xmlns:s="http://sabredav.org/ns">'
        ."<d:prop>{$props}</d:prop></d:propfind>";
}

function davIcs(string $uid, string $summary = 'Event', string $start = '20301015T100000Z', string $end = '20301015T110000Z', array $extra = []): string
{
    $lines = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Solyto//Tests//EN', 'BEGIN:VEVENT',
        "UID:{$uid}", 'DTSTAMP:20300101T000000Z', "DTSTART:{$start}", "DTEND:{$end}", "SUMMARY:{$summary}",
        ...$extra,
        'END:VEVENT', 'END:VCALENDAR',
    ];

    return implode("\r\n", $lines)."\r\n";
}

function davVcard(string $uid, string $fullName = 'Jane Doe', array $extra = []): string
{
    $lines = ['BEGIN:VCARD', 'VERSION:3.0', "UID:{$uid}", "FN:{$fullName}", 'N:Doe;Jane;;;', ...$extra, 'END:VCARD'];

    return implode("\r\n", $lines)."\r\n";
}

// A weekly series from Monday 2030-01-07 with the 2030-01-14 occurrence moved by an hour, the override written
// before or after the master.
function davSeriesWithOverride(string $uid, bool $overrideFirst): string
{
    $master = [
        'BEGIN:VEVENT', "UID:{$uid}", 'DTSTAMP:20300101T000000Z', 'DTSTART:20300107T100000Z', 'DTEND:20300107T110000Z',
        'RRULE:FREQ=WEEKLY', 'SUMMARY:Series', 'END:VEVENT',
    ];
    $override = [
        'BEGIN:VEVENT', "UID:{$uid}", 'DTSTAMP:20300101T000000Z', 'RECURRENCE-ID:20300114T100000Z',
        'DTSTART:20300114T110000Z', 'DTEND:20300114T120000Z', 'SUMMARY:Series', 'END:VEVENT',
    ];

    return implode("\r\n", [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Solyto//Tests//EN',
        ...($overrideFirst ? [...$override, ...$master] : [...$master, ...$override]),
        'END:VCALENDAR',
    ])."\r\n";
}

// A native calendar of the user connected to the fake remote collection, as the connect flow does it.
function externalCalendar(User $user, FakeCalDavServer $server, string $name = 'Remote', string $password = 'app-password'): CalendarDTO
{
    $calendar = app(DavService::class)->calendars()->create($user, CalendarDTO::fromRequest(['name' => $name]));
    $account = ExternalCalendarAccount::factory()->forUser($user)->create([
        'server_url' => FakeCalDavServer::ORIGIN.'/',
        'username' => 'alice',
        'password' => $password,
    ]);
    ExternalCalendar::factory()->forAccount($account)->create([
        'remote_url' => $server->url(),
        'calendar_id' => $calendar->calendarId,
        'instance_id' => $calendar->instanceId,
    ]);

    return app(DavService::class)->calendars()->get($user, $calendar->instanceId);
}

// Loads laravel/mcp (which registers routes/ai.php) the way App\Mcp\Providers\McpServiceProvider does when
// MCP_ENABLED is set.
function enableMcp(): void
{
    config(['mcp.enabled' => true]);

    app()->register(McpServiceProvider::class);
    app('router')->getRoutes()->refreshNameLookups();
}

function storeEvent($test, User $user, array $payload): void
{
    $instanceId = $test->actingAs($user, 'sanctum')->getJson('/api/v1/calendars')->json('data')[0]['id'];

    $test->actingAs($user, 'sanctum')
        ->postJson('/api/v1/calendars/'.$instanceId.'/events', array_merge(['is_all_day' => false], $payload, ['calendar_id' => $instanceId]))
        ->assertStatus(201);
}

// Today in $timezone: a timed and an all-day event and a daily series that started yesterday (Dentist, Holiday,
// Standup), plus one event tomorrow that a day view must leave out.
function storeDayEvents($test, User $user, string $timezone): void
{
    $today = today($timezone)->toDateString();
    $yesterday = today($timezone)->subDay()->toDateString();
    $tomorrow = today($timezone)->addDay()->toDateString();

    storeEvent($test, $user, ['title' => 'Dentist', 'start_date' => $today.' 09:30', 'end_date' => $today.' 10:30']);
    storeEvent($test, $user, ['title' => 'Holiday', 'start_date' => $today, 'end_date' => $today, 'is_all_day' => true]);
    storeEvent($test, $user, ['title' => 'Standup', 'start_date' => $yesterday.' 08:00', 'end_date' => $yesterday.' 08:15', 'is_recurring' => true, 'recurrence_rule' => 'FREQ=DAILY']);
    storeEvent($test, $user, ['title' => 'Tomorrow Meeting', 'start_date' => $tomorrow.' 09:00', 'end_date' => $tomorrow.' 10:00']);
}

// MCP is admin-only.
function mcpAdmin(): User
{
    return User::factory()->admin()->create();
}

function builtInTracker(User $user, string $key): CheckInTracker
{
    return CheckInTracker::forUser($user->id)->where('key', $key)->firstOrFail();
}

function ownTracker(User $user, CheckInTrackerType $type = CheckInTrackerType::YesNo): CheckInTracker
{
    return CheckInTracker::factory()->forUser($user)->ofType($type)->create();
}
