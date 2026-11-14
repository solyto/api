<?php

use App\Api\Calendars\Models\ExternalCalendar;
use App\Dav\Exceptions\ExternalCalendarPreconditionFailedException;
use App\Dav\Exceptions\ExternalCalendarUnavailableException;
use App\Dav\Services\RemoteCalDavClient;
use App\Dav\Services\DavService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeCalDavServer;

uses(RefreshDatabase::class);

describe('RemoteCalDavClient', function () {
    it('sends Basic auth with the account credentials', function () {
        $remote = (new FakeCalDavServer())->fake();
        externalCalendar(davUser(), $remote, password: 'secret-app-password');

        app(RemoteCalDavClient::class)->listObjects(ExternalCalendar::first());

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('alice:secret-app-password')));
    });

    it('refuses responses over the size cap', function () {
        config(['calendars.external.max_response_bytes' => 100]);
        $remote = (new FakeCalDavServer())->fake();
        externalCalendar(davUser(), $remote);
        $remote->put('big.ics', davIcs('big', str_repeat('x', 200)));

        app(RemoteCalDavClient::class)->getObject(ExternalCalendar::first(), 'big.ics');
    })->throws(ExternalCalendarUnavailableException::class);

    it('refuses an external calendar whose server address became internal', function () {
        $remote = (new FakeCalDavServer())->fake();
        externalCalendar(davUser(), $remote);
        ExternalCalendar::query()->update(['remote_url' => 'http://127.0.0.1/remote.php/dav/calendars/alice/work/']);

        try {
            app(RemoteCalDavClient::class)->listObjects(ExternalCalendar::first());
        } finally {
            expect($remote->requests)->toBeEmpty();
        }
    })->throws(ExternalCalendarUnavailableException::class);

    it('checks every redirect hop during discovery', function () {
        Http::fake(fn (Request $request) => str_contains($request->url(), '.well-known')
            ? Http::response('', 301, ['Location' => 'http://169.254.169.254/latest/'])
            : Http::response('', 404));

        $found = app(RemoteCalDavClient::class)->discover('https://cloud.example.com/', 'alice', 'app-password');

        expect($found)->toBe([]);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '169.254.169.254'));
    });

    it('refuses a redirect from https to http', function () {
        Http::fake(fn (Request $request) => str_contains($request->url(), '.well-known')
            ? Http::response('', 301, ['Location' => 'http://cloud.example.com/remote.php/dav/'])
            : Http::response('', 404));

        $found = app(RemoteCalDavClient::class)->discover('https://cloud.example.com/', 'alice', 'app-password');

        expect($found)->toBe([]);
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'http://'));
    });

    it('still refuses a stale weak ETag', function (string $method) {
        $remote = (new FakeCalDavServer())->fake();
        externalCalendar(davUser(), $remote);
        $stale = 'W/'.$remote->put('a.ics', davIcs('a', 'Old'));
        $remote->put('a.ics', davIcs('a', 'Changed elsewhere'));
        $client = app(RemoteCalDavClient::class);

        try {
            $method === 'PUT'
                ? $client->putObject(ExternalCalendar::first(), 'a.ics', davIcs('a', 'Mine'), false, $stale)
                : $client->deleteObject(ExternalCalendar::first(), 'a.ics', $stale);
        } finally {
            expect($remote->objects['a.ics']['data'])->toContain('Changed elsewhere');
        }
    })->with(['PUT', 'DELETE'])->throws(ExternalCalendarPreconditionFailedException::class);

    it('sends every tag of an If-Match list as a strong tag', function () {
        $remote = (new FakeCalDavServer())->fake();
        externalCalendar(davUser(), $remote);
        $remote->put('a.ics', davIcs('a'));

        expect(fn () => app(RemoteCalDavClient::class)->putObject(ExternalCalendar::first(), 'a.ics', davIcs('a'), false, 'W/"one", "two"'))
            ->toThrow(ExternalCalendarPreconditionFailedException::class);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request->header('If-Match') === ['"one", "two"']);
    });

    it('keeps credentials out of logs and caches', function () {
        $remote = (new FakeCalDavServer())->fake();
        $user = davUser();
        externalCalendar($user, $remote, password: 'secret-app-password');
        $remote->put('a.ics', davIcs('a'));
        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = $message->message.json_encode($message->context);
        });

        davRequest($user, 'PROPFIND', 'calendars/'.$user->email.'/remote/', davPropfindBody(['d:getetag']), ['Depth' => '1']);
        app(\App\Api\Calendars\Services\CalendarService::class)->listWidgetEvents($user);
        $remote->down = true;
        $failed = davRequest($user, 'GET', 'calendars/'.$user->email.'/remote/a.ics');
        app(\App\Api\Calendars\Services\CalendarService::class)->listWidgetEvents($user);

        expect($failed->getStatus())->toBe(503)
            ->and($failed->getBodyAsString())->not->toContain('secret-app-password')
            ->and($logged)->not->toBeEmpty()
            ->and(implode("\n", $logged))->not->toContain('secret-app-password')
            ->and(serialize(Cache::store('user_data')->getStore()))->not->toContain('secret-app-password')
            ->and(serialize(Cache::store()->getStore()))->not->toContain('secret-app-password');
    });
});
