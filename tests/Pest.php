<?php

use App\Api\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit', '../app/Api', '../app/Bots/Tests', '../app/Dav/Tests', '../app/Plugins');
uses(RefreshDatabase::class)->in('../app/Api', '../app/Plugins');

/*
|--------------------------------------------------------------------------
| Additional Expectations
|--------------------------------------------------------------------------
|
| Convenience expectations that are not part of Pest 4 out of the box.
|
*/

expect()->extend('toBeEmail', function () {
    return $this->toBeString()
        ->when(filter_var($this->value, FILTER_VALIDATE_EMAIL) === false, function ($expectation) {
            throw new \Exception(sprintf(
                'Failed asserting that [%s] is a valid email address.',
                is_scalar($this->value) ? (string) $this->value : gettype($this->value),
            ));
        });
});

/*
|--------------------------------------------------------------------------
| Shared helpers for authenticated API requests
|--------------------------------------------------------------------------
|
| These helpers are used across all feature tests (including the tests in
| the app/Api modules) to create a user and attach a Sanctum bearer token
| to API requests.
|
*/

/**
 * Create a user for API tests (verified by default so protected routes
 * that require a verified email can be exercised directly).
 */
function makeUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'email_verified_at' => now(),
    ], $attributes));
}

/**
 * Issue a fresh Sanctum personal access token for the given user and
 * return the plain-text token used in the Authorization header.
 */
function sanctumToken(User $user): string
{
    return $user->createToken('api-test')->plainTextToken;
}

/**
 * Build the Authorization headers for an authenticated API request.
 */
function authHeaders(User $user): array
{
    return ['Authorization' => 'Bearer '.sanctumToken($user)];
}

/**
 * Assert the given response is an `ApiResponse` success envelope.
 */
function expectApiSuccess($response, int $status = 200): void
{
    $response->assertStatus($status);
    $response->assertJsonPath('success', true);
}

/**
 * Assert the given response is an `ApiResponse` error envelope.
 */
function expectApiError($response, int $status): void
{
    $response->assertStatus($status);
    $response->assertJsonPath('success', false);
}

/*
|--------------------------------------------------------------------------
| DAV protocol helpers
|--------------------------------------------------------------------------
|
| DavController and public/dav.php read the raw PHP request, so Laravel's
| HTTP test helpers cannot reach them. These helpers drive the server that
| both entry points build (DavServerFactory) with Sabre request objects and
| capture the response instead of emitting it. The server uses the pgsql
| connection's PDO, so the per-test transaction still isolates DAV writes.
|
*/

/**
 * Create a verified user with a DAV principal (users get their default
 * calendar and address book from the UserObserver, the principal is not).
 */
function davUser(array $attributes = []): User
{
    $user = makeUser($attributes);
    app(\App\Dav\Services\DavService::class)->principals()->create($user->email);

    return $user;
}

/**
 * Send one request to the DAV server, authenticated with Basic auth as the
 * given user (factory password "password") unless $user is null.
 */
function davRequest(?User $user, string $method, string $path, ?string $body = null, array $headers = [], string $password = 'password'): \Sabre\HTTP\Response
{
    $server = app(\App\Dav\Factories\DavServerFactory::class)->createServer();
    $server->sapi = new class extends \Sabre\HTTP\Sapi
    {
        public static function sendResponse(\Sabre\HTTP\ResponseInterface $response): void {}
    };

    if ($user !== null) {
        $headers['Authorization'] = 'Basic '.base64_encode($user->email.':'.$password);
    }
    if ($body !== null && ! isset($headers['Content-Type'])) {
        $headers['Content-Type'] = str_starts_with(ltrim($body), '<') ? 'application/xml; charset=utf-8' : 'text/plain';
    }

    $server->httpRequest = new \Sabre\HTTP\Request($method, '/'.ltrim($path, '/'), $headers, $body);
    $server->httpResponse = new \Sabre\HTTP\Response();
    $server->exec();

    return $server->httpResponse;
}

/**
 * XPath over a DAV XML response with the d/cal/card/cs/apple/s prefixes
 * registered.
 */
function davXpath(\Sabre\HTTP\Response $response): DOMXPath
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

/**
 * Build a PROPFIND body asking for the given properties, e.g.
 * davPropfindBody(['d:displayname', 'cs:getctag']).
 */
function davPropfindBody(array $properties): string
{
    $props = implode('', array_map(fn (string $p) => "<{$p}/>", $properties));

    return '<?xml version="1.0" encoding="utf-8"?>'
        .'<d:propfind xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:card="urn:ietf:params:xml:ns:carddav"'
        .' xmlns:cs="http://calendarserver.org/ns/" xmlns:apple="http://apple.com/ns/ical/" xmlns:s="http://sabredav.org/ns">'
        ."<d:prop>{$props}</d:prop></d:propfind>";
}

/**
 * A minimal single-VEVENT iCalendar body; $extra lines go inside the VEVENT.
 */
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

/**
 * A minimal vCard 3.0 body; $extra lines go before END:VCARD.
 */
function davVcard(string $uid, string $fullName = 'Jane Doe', array $extra = []): string
{
    $lines = ['BEGIN:VCARD', 'VERSION:3.0', "UID:{$uid}", "FN:{$fullName}", 'N:Doe;Jane;;;', ...$extra, 'END:VCARD'];

    return implode("\r\n", $lines)."\r\n";
}
