<?php

use App\Dav\Exceptions\ExternalCalendarUnavailableException;
use App\Dav\Services\RemoteCalDavClient;
use App\Shared\Services\OutboundUrlGuard;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/*
 * Runs the client against a real loopback listener instead of Http::fake(),
 * so the curl options it builds (CURLOPT_RESOLVE pinning, the size-cap
 * callbacks) are exercised by an actual transfer.
 */

beforeEach(function () {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $this->port = (int) substr((string) stream_socket_get_name($socket, false), strlen('127.0.0.1:'));
    fclose($socket);

    $this->server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$this->port, base_path('tests/Fixtures/http/caldav-router.php')]);
    $this->server->start();

    for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $this->port); $i++) {
        usleep(50_000);
    }

    // dav.test does not resolve; the request only reaches the listener through the pinned address.
    $port = $this->port;
    app()->instance(OutboundUrlGuard::class, new class($port) extends OutboundUrlGuard
    {
        public function __construct(private readonly int $port)
        {
            parent::__construct();
        }

        public function check(string $url): string
        {
            return 'dav.test:'.$this->port.':127.0.0.1';
        }
    });

    // A null status means the transfer was aborted mid-flight, not refused after a full download.
    $this->failures = [];
    Log::listen(function ($message) {
        $this->failures[] = ['status' => $message->context['status'] ?? null, 'reason' => $message->context['reason'] ?? null];
    });
});

afterEach(function () {
    $this->server->stop(0);
});

function liveSend(int $port, string $path): \Illuminate\Http\Client\Response
{
    return app(RemoteCalDavClient::class)->send('alice', 'app-password', 'GET', 'http://dav.test:'.$port.$path);
}

describe('RemoteCalDavClient over a real connection', function () {
    it('pins the request to the address the guard resolved', function () {
        $response = liveSend($this->port, '/ok');

        expect($response->status())->toBe(200)
            ->and($response->body())->toBe('host=dav.test:'.$this->port);
    });

    it('aborts a response whose Content-Length is over the cap', function () {
        config(['calendars.external.max_response_bytes' => 1024]);

        expect(fn () => liveSend($this->port, '/large'))->toThrow(ExternalCalendarUnavailableException::class)
            ->and($this->failures)->toBe([['status' => null, 'reason' => 'too large']]);
    });

    it('aborts a chunked response once it grows over the cap', function () {
        config(['calendars.external.max_response_bytes' => 1024]);

        expect(fn () => liveSend($this->port, '/streamed'))->toThrow(ExternalCalendarUnavailableException::class)
            ->and($this->failures)->toBe([['status' => null, 'reason' => 'too large']]);
    });
});
