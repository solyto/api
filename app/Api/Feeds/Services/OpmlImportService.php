<?php

namespace App\Api\Feeds\Services;

use App\Api\Feeds\DTOs\FeedImportStateDTO;
use App\Api\Feeds\Enums\FeedImportStage;
use App\Api\Feeds\Enums\FeedImportStatus;
use App\Api\Feeds\Exceptions\FeedAlreadySubscribedException;
use App\Api\Feeds\Exceptions\FeedException;
use App\Api\Feeds\Exceptions\FeedImportConflictException;
use App\Api\Feeds\Exceptions\FeedImportException;
use App\Api\Feeds\Exceptions\FeedImportNotFoundException;
use App\Api\Feeds\Jobs\ImportFeedSubscriptions;
use App\Api\Feeds\Models\Feed;
use App\Api\Feeds\Models\FeedSubscription;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use DOMDocument;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimplePie\Misc;
use SimpleXMLElement;
use Throwable;

class OpmlImportService
{
    public const int MAX_FEEDS = 500;

    private const string CACHE_KEY = 'feed_import';
    private const int CACHE_TTL = 3600;
    private const string SELECT_LOCK_KEY = 'feed_import_select';
    private const int SELECT_LOCK_TTL = 10;
    private const int MAX_URL_LENGTH = 255;
    private const int MAX_TITLE_LENGTH = 255;
    private const int FETCH_CHUNK_SIZE = 25;
    private const int FETCH_TIMEOUT = 5;
    private const int MAX_FEED_BYTES = 5 * 1024 * 1024;

    /** FreshRSS scraper types: their URL is a web page, not a feed. */
    private const array SCRAPER_TYPES = ['html+xpath', 'xml+xpath', 'json+dotnotation', 'jsonfeed'];

    public function __construct(
        private readonly FeedService $feedService,
        private readonly FeedReader $feedReader,
        private readonly UserCacheService $cache,
    ) {}

    /**
     * @throws FeedImportConflictException
     * @throws FeedImportException
     */
    public function start(User $user, string $content): FeedImportStateDTO
    {
        if ($this->getState($user->id)?->stage === FeedImportStage::Running) {
            throw new FeedImportConflictException('A feed import is already running.');
        }

        $feeds = $this->parse($content);

        if ($feeds === []) {
            throw new FeedImportException('This file contains no feeds.');
        }

        if (count($feeds) > self::MAX_FEEDS) {
            throw new FeedImportException('This file contains more than 500 feeds.');
        }

        $subscribed = FeedSubscription::forUser($user->id)
            ->join('feeds', 'feeds.id', '=', 'feed_subscriptions.feed_id')
            ->whereIn('feeds.url', array_column($feeds, 'url'))
            ->pluck('feeds.url')
            ->flip();

        $state = new FeedImportStateDTO(FeedImportStage::Parsed, array_map(fn (array $feed) => [
            'url' => $feed['url'],
            'title' => $feed['title'],
            'already_subscribed' => $subscribed->has($feed['url']),
            'status' => null,
        ], $feeds));

        $this->saveState($user->id, $state);

        return $state;
    }

    /**
     * @param  array<int, string>  $urls
     *
     * @throws FeedImportNotFoundException
     * @throws FeedImportConflictException
     * @throws FeedImportException
     */
    public function select(User $user, array $urls): FeedImportStateDTO
    {
        // Without the lock, a double submit passes the Parsed check twice and dispatches two jobs.
        $lock = Cache::lock(self::SELECT_LOCK_KEY.'_'.$user->id, self::SELECT_LOCK_TTL);

        if (! $lock->get()) {
            throw new FeedImportConflictException('This feed import has already started.');
        }

        try {
            $state = $this->state($user);

            if ($state->stage !== FeedImportStage::Parsed) {
                throw new FeedImportConflictException('This feed import has already started.');
            }

            $selected = array_flip($urls);
            $feeds = array_values(array_filter(
                $state->feeds,
                fn (array $feed) => isset($selected[$feed['url']]) && ! $feed['already_subscribed']
            ));

            if ($feeds === []) {
                throw new FeedImportException('None of the selected feeds can be imported.');
            }

            $state = new FeedImportStateDTO(FeedImportStage::Running, array_map(
                fn (array $feed) => [...$feed, 'status' => FeedImportStatus::Pending],
                $feeds
            ));

            $this->saveState($user->id, $state);

            ImportFeedSubscriptions::dispatch($user->id);

            return $state;
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws FeedImportNotFoundException
     */
    public function state(User $user): FeedImportStateDTO
    {
        return $this->getState($user->id) ?? throw new FeedImportNotFoundException('No feed import found.');
    }

    public function run(string $userId): void
    {
        $state = $this->getState($userId);
        $user = User::find($userId);

        if (! $state || ! $user || $state->stage !== FeedImportStage::Running) {
            return;
        }

        $known = Feed::whereIn('url', array_column($state->feeds, 'url'))->pluck('url')->flip();
        $toFetch = [];

        try {
            foreach ($state->feeds as $index => $feed) {
                if ($known->has($feed['url'])) {
                    $state->feeds[$index]['status'] = $this->subscribe($user, $feed, fn () => null);
                } else {
                    $toFetch[$index] = $feed;
                }
            }
        } finally {
            $this->saveState($userId, $state);
        }

        foreach (array_chunk($toFetch, self::FETCH_CHUNK_SIZE, true) as $chunk) {
            try {
                $responses = Http::pool(fn (Pool $pool) => array_map(
                    fn (array $feed) => $pool->as($feed['url'])
                        ->withUserAgent(Misc::get_default_useragent())
                        ->timeout(self::FETCH_TIMEOUT)
                        // Aborts just this transfer; a throwing Guzzle progress callback would reject the
                        // whole chunk. An aborted transfer still comes back as a Response, see isFetchedFeed().
                        ->withOptions(['curl' => [
                            CURLOPT_NOPROGRESS => false,
                            CURLOPT_XFERINFOFUNCTION => fn ($handle, int $downloadTotal, int $downloaded) => (int) (
                                $downloadTotal > self::MAX_FEED_BYTES || $downloaded > self::MAX_FEED_BYTES
                            ),
                        ]])
                        ->get($feed['url']),
                    $chunk
                ));

                foreach ($chunk as $index => $feed) {
                    $response = $responses[$feed['url']] ?? null;

                    $state->feeds[$index]['status'] = $this->isFetchedFeed($response)
                        ? $this->subscribe($user, $feed, fn () => $this->feedReader->parseFeedData($response->body()))
                        : FeedImportStatus::Unreachable;
                }
            } finally {
                $this->saveState($userId, $state);
            }
        }

        $state->stage = FeedImportStage::Finished;
        $this->saveState($userId, $state);
    }

    public function fail(string $userId): void
    {
        $state = $this->getState($userId);

        if (! $state) {
            return;
        }

        foreach ($state->feeds as $index => $feed) {
            if ($feed['status'] === FeedImportStatus::Pending) {
                $state->feeds[$index]['status'] = FeedImportStatus::Unreachable;
            }
        }

        $state->stage = FeedImportStage::Finished;
        $this->saveState($userId, $state);
    }

    /**
     * @return array<int, array{url: string, title: string}>
     *
     * @throws FeedImportException
     */
    private function parse(string $content): array
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $content)) {
            throw new FeedImportException('This file could not be read as OPML.');
        }

        $content = preg_replace('/&(?!(?:[a-zA-Z]+|#[0-9]+|#x[0-9a-fA-F]+);)/', '&amp;', $content);

        // Loaded through DOM: simplexml_load_string() with LIBXML_RECOVER returns
        // an unusable element for non-XML input that throws on first access.
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = trim($content) !== ''
            && $document->loadXML($content, LIBXML_RECOVER | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($document->doctype !== null) {
            throw new FeedImportException('This file could not be read as OPML.');
        }

        $xml = $loaded && $document->documentElement ? simplexml_import_dom($document) : null;

        if (! $xml || ! isset($xml->body)) {
            throw new FeedImportException('This file could not be read as OPML.');
        }

        $feeds = [];
        $this->collect($xml->body, $feeds);

        return array_values($feeds);
    }

    /**
     * @param  array<string, array{url: string, title: string}>  $feeds
     */
    private function collect(SimpleXMLElement $parent, array &$feeds): void
    {
        foreach ($parent->outline as $outline) {
            $url = trim((string) ($outline['xmlUrl'] ?? $outline['xmlURL'] ?? $outline['url'] ?? ''));

            if ($url === '') {
                $this->collect($outline, $feeds);

                continue;
            }

            if (in_array(strtolower((string) $outline['type']), self::SCRAPER_TYPES, true)
                || ! preg_match('#^https?://#i', $url)
                || strlen($url) > self::MAX_URL_LENGTH
                || isset($feeds[$url])) {
                continue;
            }

            $title = $this->feedService->plainTitle((string) $outline['title'])
                ?: $this->feedService->plainTitle((string) $outline['text'])
                ?: $url;

            $feeds[$url] = ['url' => $url, 'title' => mb_substr($title, 0, self::MAX_TITLE_LENGTH)];
        }
    }

    /**
     * @param  array{url: string, title: string}  $feed
     * @param  \Closure(): (array|false|null)  $feedData
     */
    private function subscribe(User $user, array $feed, \Closure $feedData): FeedImportStatus
    {
        try {
            $this->feedService->createImportedSubscription($user, $feed['title'], $feed['url'], $feedData());

            return FeedImportStatus::Subscribed;
        } catch (FeedAlreadySubscribedException) {
            return FeedImportStatus::AlreadySubscribed;
        } catch (FeedException) {
            return FeedImportStatus::NotAFeed;
        } catch (Throwable $e) {
            // One odd feed must not end the job ($tries = 1) and leave every later feed untried.
            Log::warning('Feed import: subscribing a feed failed', [
                'user_id' => $user->id,
                'url' => $feed['url'],
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return FeedImportStatus::Unreachable;
        }
    }

    /**
     * @phpstan-assert-if-true Response $response
     */
    private function isFetchedFeed(mixed $response): bool
    {
        return $response instanceof Response
            && $response->successful()
            && (int) $response->header('Content-Length') <= self::MAX_FEED_BYTES
            && strlen($response->body()) <= self::MAX_FEED_BYTES;
    }

    private function getState(string $userId): ?FeedImportStateDTO
    {
        $state = $this->cache->get([self::CACHE_KEY, $userId]);

        return $state instanceof FeedImportStateDTO ? $state : null;
    }

    private function saveState(string $userId, FeedImportStateDTO $state): void
    {
        $this->cache->store([self::CACHE_KEY, $userId], self::CACHE_TTL, $state);
    }
}
