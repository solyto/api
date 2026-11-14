<?php

use App\Api\Feeds\Services\FeedReader;
use App\Shared\Services\OutboundUrlGuard;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/*
 * Runs FeedReader against a real loopback listener instead of Http::fake(),
 * so the curl options it builds (CURLOPT_RESOLVE pinning, decompression and
 * the size-capped sink) are exercised by an actual transfer.
 */

beforeEach(function () {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $this->port = (int) substr((string) stream_socket_get_name($socket, false), strlen('127.0.0.1:'));
    fclose($socket);

    $this->server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$this->port, base_path('tests/Fixtures/http/feed-router.php')]);
    $this->server->start();

    for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $this->port); $i++) {
        usleep(50_000);
    }

    // feed.test does not resolve; the request only reaches the listener through the pinned address.
    // Every other host goes through the real check, which refuses the loopback address.
    $port = $this->port;
    app()->instance(OutboundUrlGuard::class, new class($port) extends OutboundUrlGuard
    {
        public function __construct(private readonly int $port)
        {
            parent::__construct();
        }

        public function check(string $url): string
        {
            return parse_url($url, PHP_URL_HOST) === 'feed.test' ? 'feed.test:'.$this->port.':127.0.0.1' : parent::check($url);
        }
    });

    $this->warnings = [];
    Log::listen(function ($message) {
        $this->warnings[] = $message->message;
    });
});

afterEach(function () {
    $this->server->stop(0);
});

describe('FeedReader over a real connection', function () {
    it('inflates a gzip feed fetched from the pinned address', function () {
        $data = app(FeedReader::class)->getFeedData('http://feed.test:'.$this->port.'/feed');

        expect($data['title'])->toBe('Fixture Feed')
            ->and($data['items'])->toHaveCount(2);
    });

    it('aborts a gzip bomb once it inflates past the cap', function () {
        $started = microtime(true);

        $responses = app(FeedReader::class)->fetchMany(['http://feed.test:'.$this->port.'/gzip-bomb'], 30);

        // The stream would inflate to 4 GB; stopping well inside the timeout shows the cap ended it.
        expect($responses)->toBe([null])
            ->and($this->warnings)->toBe(['Feed fetch: response over the size cap'])
            ->and(microtime(true) - $started)->toBeLessThan(10);
    });

    it('rejects a gzip bomb when subscribing', function () {
        expect(app(FeedReader::class)->getFeedData('http://feed.test:'.$this->port.'/gzip-bomb'))->toBeFalse();
    });

    it('follows a relative redirect', function () {
        $response = app(FeedReader::class)->fetchMany(['http://feed.test:'.$this->port.'/redirect-relative'], 5)[0];

        expect($response?->status())->toBe(200)
            ->and($response->body())->toContain('Fixture Feed');
    });

    it('does not follow a redirect to a blocked address', function () {
        expect(app(FeedReader::class)->fetchMany(['http://feed.test:'.$this->port.'/redirect-loopback'], 5))->toBe([null])
            ->and(app(FeedReader::class)->getFeedData('http://feed.test:'.$this->port.'/redirect-loopback'))->toBeFalse();
    });
});
