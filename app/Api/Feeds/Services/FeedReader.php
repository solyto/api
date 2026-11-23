<?php

namespace App\Api\Feeds\Services;

use App\Shared\Pulse\PulseMetrics;
use App\Shared\Exceptions\BlockedUrlException;
use App\Shared\Services\OutboundUrlGuard;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use SimplePie\Misc;
use SimplePie\SimplePie;

/**
 * Every download goes through fetchMany(): each hop is checked by the
 * OutboundUrlGuard and pinned to the address it checked (CURLOPT_RESOLVE),
 * redirects are followed by hand, and a body is cut off once its
 * decompressed size passes MAX_FEED_BYTES. SimplePie fetches through the
 * same path (httpClient()), so its feed autodiscovery is guarded too.
 * Bodies are requested compressed; a body that does not decode as its
 * Content-Encoding says is fetched once more without decoding.
 */
class FeedReader
{
    private const int MAX_FEED_BYTES = 5 * 1024 * 1024;
    private const int MAX_REDIRECTS = 5;
    private const int FETCH_TIMEOUT = 10;
    private const int OG_IMAGE_TIMEOUT = 5;

    public function __construct(private readonly OutboundUrlGuard $guard) {}

    /**
     * @throws BlockedUrlException when the feed URL itself points at a non-public address
     */
    public function getItems(string $url): array|false
    {
        $pie = $this->fetchFeed($url);
        $items = $pie->get_items();

        if (empty($items)) {
            return false;
        }

        return $items;
    }

    /**
     * 'url' is the feed that was read: $url itself when it is a feed, else
     * the feed discovered on that page. It is the URL before redirects:
     * httpClient() reports every redirect to SimplePie as a temporary one.
     *
     * @return array{url: string, title: ?string, items: array<int, \SimplePie\Item>}|false
     *
     * @throws BlockedUrlException when the feed URL itself points at a non-public address
     */
    public function getFeedData(string $url): array|false
    {
        $pie = $this->fetchFeed($url);

        // The feed SimplePie found first stays; only an empty one falls back to a feed under the page's path.
        if (empty($pie->get_items())) {
            $preferred = $this->preferredDiscoveredFeed($url, $pie);

            if ($preferred !== null && $preferred !== $pie->subscribe_url(true)) {
                $pie = $this->fetchFeed($preferred);
            }
        }

        $items = $pie->get_items();

        if (empty($items)) {
            return false;
        }

        return [
            'url' => $pie->subscribe_url(true) ?? $url,
            'title' => $pie->get_title() ?: null,
            'items' => $items,
        ];
    }

    /**
     * SimplePie loads the first feed a page links to, often the site-wide
     * one (angak.fr: an empty /feed/). A discovered feed under the page's
     * own path is the one the user pasted the page for
     * (/actualites/ → /actualites/feed/). A root page has no such feed.
     * A comments feed is never one: WordPress links each post's comments feed under the post's path.
     */
    private function preferredDiscoveredFeed(string $pageUrl, SimplePie $pie): ?string
    {
        $page = parse_url($pageUrl);
        $prefix = rtrim($page['path'] ?? '', '/').'/';

        if ($prefix === '/') {
            return null;
        }

        foreach ($pie->get_all_discovered_feeds() ?? [] as $candidate) {
            $candidateUrl = $candidate->get_permanent_uri();
            $parts = parse_url($candidateUrl);

            if (strcasecmp($parts['host'] ?? '', $page['host'] ?? '') === 0
                && str_starts_with($parts['path'] ?? '', $prefix)
                && ! $this->isCommentsFeed($candidate->get_body_content())) {
                return $candidateUrl;
            }
        }

        return null;
    }

    // The feed's own title, as WordPress writes it in the languages we support: "Comments on: …",
    // "Kommentare zu: …", "Commentaires sur : …", "Comentarios en: …", "Commenti a: …", "Комментарии к записи: …".
    private function isCommentsFeed(string $body): bool
    {
        return preg_match('#<title[^>]*>(.*?)</title>#is', $body, $title) === 1
            && preg_match('/comment|kommentar|comentario|коммент/iu', $title[1]) === 1;
    }

    /**
     * A fresh SimplePie per call: raw data set on a shared instance would
     * stay there and be served to every later call. The charset of
     * $contentType (the HTTP header) comes before the XML declaration, as it
     * does when SimplePie fetches the feed itself.
     */
    public function parseFeedData(string $content, ?string $contentType = null): array|false
    {
        $pie = new SimplePie;
        $pie->enable_cache(false);
        $pie->set_raw_data($content);

        if ($contentType !== null && preg_match('/;\x20?charset=([^;]*)/i', $contentType, $charset)) {
            $pie->set_input_encoding(trim($charset[1], " \"'"));
        }

        $pie->init();

        $items = $pie->get_items();

        if (empty($items)) {
            return false;
        }

        return [
            'title' => $pie->get_title() ?: null,
            'items' => $items,
        ];
    }

    /**
     * Fetches all URLs concurrently, one pool per redirect hop. A URL comes
     * back as null when it or a hop it redirects to is blocked by the guard,
     * the connection fails, the body is over MAX_FEED_BYTES, it redirects to
     * a Location that cannot be parsed, or it redirects more than
     * MAX_REDIRECTS times; otherwise as the final response, whatever its
     * status. The final response's effectiveUri() is the URL after redirects.
     *
     * @param  array<array-key, string>  $urls
     * @param  array<string, string>  $headers
     * @return array<array-key, Response|null> keyed like $urls
     */
    public function fetchMany(array $urls, int $timeout, array $headers = []): array
    {
        $results = [];
        $pending = $urls;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS && $pending !== []; $hop++) {
            $pins = [];

            foreach ($pending as $key => $url) {
                try {
                    $pins[$key] = $this->guard->check($url);
                } catch (BlockedUrlException) {
                    $results[$key] = null;
                }
            }

            $tooLarge = [];
            $responses = $pins === [] ? [] : $this->pool($pins, $pending, $timeout, $headers, true, $tooLarge);

            // What curl did on CURLE_BAD_CONTENT_ENCODING for SimplePie's own File: ask again
            // without decoding, for servers that label a plain body as gzip.
            $undecodable = array_filter(
                $pins,
                fn ($pin, $key) => ! isset($tooLarge[$key])
                    && ($responses[$key] ?? null) instanceof Response
                    && $responses[$key]->transferStats?->getHandlerErrorData() === CURLE_BAD_CONTENT_ENCODING,
                ARRAY_FILTER_USE_BOTH
            );

            if ($undecodable !== []) {
                $responses = array_replace($responses, $this->pool($undecodable, $pending, $timeout, $headers, false, $tooLarge));
            }

            $next = [];

            foreach (array_keys($pins) as $key) {
                $response = $responses[$key] ?? null;

                if (isset($tooLarge[$key]) || ($response instanceof Response && $this->isTooLarge($response))) {
                    Log::warning('Feed fetch: response over the size cap', ['host' => parse_url($pending[$key], PHP_URL_HOST)]);
                    $results[$key] = null;
                } elseif (! $response instanceof Response) {
                    $results[$key] = null;
                } elseif ($response->redirect() && $response->header('Location') !== '') {
                    try {
                        $next[$key] = (string) UriResolver::resolve(new Uri($pending[$key]), new Uri($response->header('Location')));
                    } catch (MalformedUriException) {
                        $results[$key] = null;
                    }
                } else {
                    $results[$key] = $response;
                }
            }

            $pending = $next;
        }

        $ordered = [];

        foreach (array_keys($urls) as $key) {
            $ordered[$key] = $results[$key] ?? null;
        }

        return $ordered;
    }

    /**
     * One request per pinned URL, all in one pool. Without $decode the body is
     * kept as sent and identity is asked for instead of compression.
     *
     * @param  array<array-key, string>  $pins
     * @param  array<array-key, string>  $urls
     * @param  array<string, string>  $headers
     * @param  array<array-key, true>  $tooLarge  set for every key whose body passed MAX_FEED_BYTES
     * @return array<array-key, Response|\Throwable>
     */
    private function pool(array $pins, array $urls, int $timeout, array $headers, bool $decode, array &$tooLarge): array
    {
        // Plain closures: an arrow function would capture $tooLarge by value.
        return Http::pool(function (Pool $pool) use ($pins, $urls, $timeout, $headers, $decode, &$tooLarge) {
            foreach ($pins as $key => $pin) {
                $pool->as((string) $key)
                    ->withUserAgent(Misc::get_default_useragent())
                    ->withHeaders(['Accept-Encoding' => $decode ? 'gzip, deflate' : 'identity', ...$headers])
                    ->timeout($timeout)
                    ->connectTimeout($timeout)
                    ->withoutRedirecting()
                    ->withOptions([
                        'decode_content' => $decode,
                        'curl' => [CURLOPT_RESOLVE => [$pin]],
                        'sink' => $this->cappedSink(function () use (&$tooLarge, $key) {
                            $tooLarge[$key] = true;
                        }),
                    ])
                    ->get($urls[$key]);
            }
        });
    }

    /**
     * @throws BlockedUrlException
     */
    private function fetchFeed(string $url): SimplePie
    {
        // Checked up front so callers can tell a forbidden URL from one that is just unreachable.
        $this->guard->check($url);

        $factory = new HttpFactory;
        $pie = new SimplePie;
        $pie->enable_cache(false);
        $pie->set_http_client($this->httpClient(), $factory, $factory);
        $pie->set_feed_url($url);
        $pie->init();

        return $pie;
    }

    /**
     * fetchMany() follows redirects through the guard itself. SimplePie
     * resolves relative links (autodiscovery, items) against the last URL it
     * requested, so a redirected fetch is answered with one 307 to the final
     * URL, and SimplePie's request for that URL with the response already
     * fetched.
     */
    private function httpClient(): ClientInterface
    {
        return new class($this, self::FETCH_TIMEOUT) implements ClientInterface
        {
            /** @var array<string, ResponseInterface> final URL => its response */
            private array $redirected = [];

            public function __construct(private readonly FeedReader $reader, private readonly int $timeout) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $url = (string) $request->getUri();

                if (isset($this->redirected[$url])) {
                    $response = $this->redirected[$url];
                    unset($this->redirected[$url]);

                    return $response;
                }

                $headers = $request->hasHeader('Accept') ? ['Accept' => $request->getHeaderLine('Accept')] : [];
                $response = $this->reader->fetchMany([$url], $this->timeout, $headers)[0]
                    ?? throw new TransferException('The feed could not be fetched.');
                $finalUrl = (string) $response->effectiveUri();

                if ($finalUrl === '' || $finalUrl === $url) {
                    return $response->toPsrResponse();
                }

                $this->redirected[$finalUrl] = $response->toPsrResponse();

                return new PsrResponse(307, ['Location' => $finalUrl]);
            }
        };
    }

    /**
     * curl hands the sink decompressed bytes, so counting here caps what a
     * gzip body inflates to; a progress callback only sees the compressed
     * bytes on the wire. Accepting fewer bytes than offered makes curl abort
     * just this transfer, where a throw would reject the whole pool.
     */
    private function cappedSink(\Closure $onTooLarge): StreamInterface
    {
        $stream = Utils::streamFor(Utils::tryFopen('php://temp', 'w+'));
        $written = 0;

        return FnStream::decorate($stream, [
            'write' => function (string $data) use ($stream, &$written, $onTooLarge): int {
                $written += strlen($data);

                if ($written > self::MAX_FEED_BYTES) {
                    $onTooLarge();

                    return 0;
                }

                return $stream->write($data);
            },
        ]);
    }

    private function isTooLarge(Response $response): bool
    {
        return (int) $response->header('Content-Length') > self::MAX_FEED_BYTES
            || strlen($response->body()) > self::MAX_FEED_BYTES;
    }

    /**
     * Hosts that only serve tracking pixels. Their images are skipped even when
     * no size is given, so clients never load the tracker. VG Wort counts reads
     * for German publishers on per-server subdomains, hence the suffix match.
     */
    private const array TRACKER_HOSTS = [
        'cpx.golem.de',
        'met.vgwort.de',
        'pixel.wp.com',
        'stats.wordpress.com',
        'feeds.feedburner.com',
    ];

    public function extractImageUrl(\SimplePie\Item $item): ?string
    {
        $thumbnail = $item->get_item_tags('http://search.yahoo.com/mrss/', 'thumbnail');
        if (! empty($thumbnail[0]['attribs']['']['url'])) {
            $attribs = $thumbnail[0]['attribs'][''];
            if ($this->isUsableImage($attribs['url'], $attribs['width'] ?? null, $attribs['height'] ?? null)) {
                return $attribs['url'];
            }
        }

        $content = $item->get_item_tags('http://search.yahoo.com/mrss/', 'content');
        if (! empty($content)) {
            foreach ($content as $mediaContent) {
                $attribs = $mediaContent['attribs'][''] ?? [];
                $medium = $attribs['medium'] ?? '';
                $type = $attribs['type'] ?? '';
                if (($medium === 'image' || str_starts_with($type, 'image/'))
                    && ! empty($attribs['url'])
                    && $this->isUsableImage($attribs['url'], $attribs['width'] ?? null, $attribs['height'] ?? null)) {
                    return $attribs['url'];
                }
            }
        }

        $enclosures = $item->get_enclosures();
        if ($enclosures) {
            foreach ($enclosures as $enclosure) {
                if (($enclosure->get_medium() === 'image' || str_starts_with((string) $enclosure->get_type(), 'image/'))
                    && $enclosure->get_link()
                    && $this->isUsableImage($enclosure->get_link(), $enclosure->get_width(), $enclosure->get_height())) {
                    return $enclosure->get_link();
                }
            }
        }

        // get_content() is content:encoded (falling back to the description), so
        // the full article HTML is searched before the teaser.
        foreach ([$item->get_content(), $item->get_description()] as $html) {
            $imageUrl = $html ? $this->firstImageInHtml($html) : null;
            if ($imageUrl) {
                return $imageUrl;
            }
        }

        return $this->extractOgImage($item->get_link());
    }

    /**
     * Many feeds (e.g. Golem) end every item's HTML with a 1x1 tracking pixel,
     * which must never become the item image: it is invisible, and every client
     * would load the tracker.
     */
    private function firstImageInHtml(string $html): ?string
    {
        preg_match_all('/<img\b[^>]*>/i', $html, $tags);

        foreach ($tags[0] as $tag) {
            if (! preg_match('/\ssrc=["\']([^"\']+)["\']/i', $tag, $src)) {
                continue;
            }

            $url = html_entity_decode($src[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($this->isUsableImage($url, $this->htmlAttribute($tag, 'width'), $this->htmlAttribute($tag, 'height'))) {
                return $url;
            }
        }

        return null;
    }

    private function htmlAttribute(string $tag, string $name): ?string
    {
        return preg_match('/\s'.$name.'=["\']?([^"\'\s>]+)/i', $tag, $matches) ? $matches[1] : null;
    }

    private function isUsableImage(string $url, int|string|null $width, int|string|null $height): bool
    {
        foreach ([$width, $height] as $size) {
            if ($size !== null && $size !== '' && (int) $size <= 1) {
                return false;
            }
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach (self::TRACKER_HOSTS as $trackerHost) {
            if ($host === $trackerHost || str_ends_with($host, '.'.$trackerHost)) {
                return false;
            }
        }

        return true;
    }

    private function extractOgImage(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $startedAt = PulseMetrics::startTimer();

        try {
            $response = $this->fetchMany([$url], self::OG_IMAGE_TIMEOUT, [
                'User-Agent' => 'Mozilla/5.0 (compatible; FeedBot/1.0)',
            ])[0];

            if (! $response?->successful()) {
                return null;
            }

            $html = $response->body();

            if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
                return $matches[1];
            }

            if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\'][^>]*>/i', $html, $matches)) {
                return $matches[1];
            }
        } catch (\Throwable) {
            return null;
        } finally {
            PulseMetrics::countOgImageLookup(PulseMetrics::elapsedMs($startedAt));
        }

        return null;
    }
}
