<?php

namespace App\Dav\Services;

use App\Api\Calendars\Models\ExternalCalendar;
use App\Dav\Exceptions\ExternalCalendarPreconditionFailedException;
use App\Dav\Exceptions\ExternalCalendarUnavailableException;
use App\Shared\Exceptions\BlockedUrlException;
use App\Shared\Services\OutboundUrlGuard;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP client for the user's own CalDAV server behind an external calendar.
 * Object names are translated by file name only: the last segment of a
 * remote href, URL-decoded, is our object URI; hrefs that are not direct
 * children of the remote collection are skipped. Credentials never reach
 * logs, caches or exception messages.
 */
class RemoteCalDavClient
{
    private const string NS_DAV = 'DAV:';
    private const string NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';
    private const string NS_CALENDARSERVER = 'http://calendarserver.org/ns/';
    private const int MAX_REDIRECTS = 5;

    public function __construct(private readonly OutboundUrlGuard $guard) {}

    /**
     * @return array{uri: string, etag: ?string, size: int, calendardata: string}|null
     */
    public function getObject(ExternalCalendar $calendar, string $name): ?array
    {
        $response = $this->sendFor($calendar, 'GET', $this->objectUrl($calendar, $name));

        if (in_array($response->status(), [404, 410], true)) {
            return null;
        }

        if (!$response->successful()) {
            throw $this->unavailable($calendar->remote_url, $response->status());
        }

        return [
            'uri' => $name,
            'etag' => $this->normalizeEtag($response->header('ETag')),
            'size' => strlen($response->body()),
            'calendardata' => $response->body(),
        ];
    }

    /**
     * Creates refuse to overwrite (If-None-Match: *); updates pass the
     * client's If-Match through, so a remote 412 means the remote won.
     *
     * @return string|null the new ETag, when the remote sends one
     */
    public function putObject(ExternalCalendar $calendar, string $name, string $data, bool $create, ?string $ifMatch = null): ?string
    {
        $headers = ['Content-Type' => 'text/calendar; charset=utf-8'];
        if ($create) {
            $headers['If-None-Match'] = '*';
        } elseif ($ifMatch !== null && $ifMatch !== '') {
            $headers['If-Match'] = $this->ifMatchHeader($ifMatch);
        }

        $response = $this->sendFor($calendar, 'PUT', $this->objectUrl($calendar, $name), $headers, $data);

        if ($response->status() === 412) {
            throw new ExternalCalendarPreconditionFailedException();
        }

        if (!$response->successful()) {
            throw $this->unavailable($calendar->remote_url, $response->status());
        }

        return $this->normalizeEtag($response->header('ETag'));
    }

    public function deleteObject(ExternalCalendar $calendar, string $name, ?string $ifMatch = null): void
    {
        $headers = $ifMatch !== null && $ifMatch !== '' ? ['If-Match' => $this->ifMatchHeader($ifMatch)] : [];

        $response = $this->sendFor($calendar, 'DELETE', $this->objectUrl($calendar, $name), $headers);

        if ($response->status() === 412) {
            throw new ExternalCalendarPreconditionFailedException();
        }

        if (!$response->successful() && !in_array($response->status(), [404, 410], true)) {
            throw $this->unavailable($calendar->remote_url, $response->status());
        }
    }

    /**
     * @return array<int, array{uri: string, etag: ?string, size: int}>
     */
    public function listObjects(ExternalCalendar $calendar): array
    {
        $body = $this->xml('<d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/><d:getetag/><d:getcontenttype/><d:getcontentlength/></d:prop></d:propfind>');
        $responses = $this->multistatus($calendar, 'PROPFIND', $calendar->remote_url, $body, '1')['responses'];

        $objects = [];
        foreach ($responses as $response) {
            if (isset($response['props']['{DAV:}resourcetype']) && $this->isCollection($response['props']['{DAV:}resourcetype'])) {
                continue;
            }

            $name = $this->childName($calendar, $response['href']);
            if ($name === null) {
                continue;
            }

            $objects[] = [
                'uri' => $name,
                'etag' => $this->normalizeEtag($this->text($response['props']['{DAV:}getetag'] ?? null)),
                'size' => (int) $this->text($response['props']['{DAV:}getcontentlength'] ?? null),
            ];
        }

        return $objects;
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, array{uri: string, etag: ?string, size: int, calendardata: string}>
     */
    public function multiget(ExternalCalendar $calendar, array $names): array
    {
        if ($names === []) {
            return [];
        }

        $path = parse_url($calendar->remote_url, PHP_URL_PATH) ?: '/';
        $hrefs = implode('', array_map(
            fn (string $name) => '<d:href>'.htmlspecialchars(rtrim($path, '/').'/'.rawurlencode($name), ENT_XML1).'</d:href>',
            array_values($names)
        ));
        $body = $this->xml('<c:calendar-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><c:calendar-data/></d:prop>'.$hrefs.'</c:calendar-multiget>');

        return $this->objectsWithData($calendar, $this->multistatus($calendar, 'REPORT', $calendar->remote_url, $body, '1')['responses']);
    }

    /**
     * A calendar-query narrowed to one component type and, when given, a
     * time range. Callers apply their exact filter on the returned data.
     *
     * @return array<int, array{uri: string, etag: ?string, size: int, calendardata: string}>
     */
    public function query(ExternalCalendar $calendar, ?string $component, ?\DateTimeInterface $start = null, ?\DateTimeInterface $end = null): array
    {
        $timeRange = '';
        if ($start !== null || $end !== null) {
            $attributes = '';
            if ($start !== null) {
                $attributes .= ' start="'.$this->utc($start).'"';
            }
            if ($end !== null) {
                $attributes .= ' end="'.$this->utc($end).'"';
            }
            $timeRange = "<c:time-range{$attributes}/>";
        }

        $filter = $component !== null
            ? '<c:comp-filter name="VCALENDAR"><c:comp-filter name="'.htmlspecialchars($component, ENT_XML1).'">'.$timeRange.'</c:comp-filter></c:comp-filter>'
            : '<c:comp-filter name="VCALENDAR"/>';

        $body = $this->xml('<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><c:calendar-data/></d:prop><c:filter>'.$filter.'</c:filter></c:calendar-query>');

        return $this->objectsWithData($calendar, $this->multistatus($calendar, 'REPORT', $calendar->remote_url, $body, '1')['responses']);
    }

    /**
     * The remote collection's ctag and sync token. The sync token is null
     * when the remote does not support sync-collection.
     *
     * @return array{ctag: ?string, sync_token: ?string}
     */
    public function collectionState(ExternalCalendar $calendar): array
    {
        $body = $this->xml('<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/"><d:prop><cs:getctag/><d:sync-token/><d:supported-report-set/></d:prop></d:propfind>');
        $responses = $this->multistatus($calendar, 'PROPFIND', $calendar->remote_url, $body, '0')['responses'];
        $props = $responses[0]['props'] ?? [];

        $syncToken = $this->text($props['{DAV:}sync-token'] ?? null);
        if (isset($props['{DAV:}supported-report-set']) && $props['{DAV:}supported-report-set']->getElementsByTagNameNS(self::NS_DAV, 'sync-collection')->length === 0) {
            $syncToken = null;
        }

        return [
            'ctag' => $this->text($props['{'.self::NS_CALENDARSERVER.'}getctag'] ?? null),
            'sync_token' => $syncToken,
        ];
    }

    /**
     * @return array{sync_token: string, modified: array<int, string>, deleted: array<int, string>, truncated: bool}|null
     *         null when the remote no longer accepts the token
     */
    public function syncCollection(ExternalCalendar $calendar, string $syncToken): ?array
    {
        $body = $this->xml('<d:sync-collection xmlns:d="DAV:"><d:sync-token>'.htmlspecialchars($syncToken, ENT_XML1).'</d:sync-token><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>');
        $response = $this->sendFor($calendar, 'REPORT', $calendar->remote_url, ['Content-Type' => 'application/xml; charset=utf-8'], $body);

        if (in_array($response->status(), [400, 403, 409], true)) {
            return null;
        }

        $result = $this->parseMultistatusResponse($calendar->remote_url, $response);
        if ($result['sync_token'] === null) {
            return null;
        }

        $modified = [];
        $deleted = [];
        $truncated = false;

        foreach ($result['responses'] as $entry) {
            $name = $this->childName($calendar, $entry['href']);

            if ($name === null) {
                $truncated = $truncated || $entry['status'] === 507;
                continue;
            }

            if (in_array($entry['status'], [404, 410], true)) {
                $deleted[] = $name;
            } else {
                $modified[] = $name;
            }
        }

        return [
            'sync_token' => $result['sync_token'],
            'modified' => $modified,
            'deleted' => $deleted,
            'truncated' => $truncated,
        ];
    }

    /**
     * Generic CalDAV discovery: /.well-known/caldav (RFC 6764) or the URL
     * itself → current-user-principal (RFC 5397) → calendar-home-set →
     * calendar collections. Without a principal it falls back to the
     * Nextcloud path principals/users/<username>/.
     *
     * @return array<int, array{url: string, name: string, color: string}>
     *
     * @throws BlockedUrlException
     */
    public function discover(string $url, string $username, string $password): array
    {
        $this->guard->check($url);

        $base = rtrim($url, '/').'/';
        $origin = $this->origin($base);
        $credentials = [$username, $password];

        $principal = $this->findHref($credentials, $origin.'.well-known/caldav', '<d:current-user-principal/>', self::NS_DAV, 'current-user-principal')
            ?? $this->findHref($credentials, $base, '<d:current-user-principal/>', self::NS_DAV, 'current-user-principal')
            ?? $base.'principals/users/'.rawurlencode($username).'/';

        $home = $this->findHref($credentials, $principal, '<c:calendar-home-set/>', self::NS_CALDAV, 'calendar-home-set');
        if ($home === null) {
            return [];
        }

        $body = $this->xml('<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:ical="http://apple.com/ns/ical/"><d:prop><d:displayname/><d:resourcetype/><c:supported-calendar-component-set/><ical:calendar-color/></d:prop></d:propfind>');

        try {
            [$response, $home] = $this->sendFollowing($credentials, 'PROPFIND', $home, ['Depth' => '1', 'Content-Type' => 'application/xml; charset=utf-8'], $body);
            $responses = $this->parseMultistatusResponse($home, $response)['responses'];
        } catch (ExternalCalendarUnavailableException) {
            return [];
        }

        $calendars = [];
        foreach ($responses as $entry) {
            $resourceType = $entry['props']['{DAV:}resourcetype'] ?? null;
            if (!$resourceType || $resourceType->getElementsByTagNameNS(self::NS_CALDAV, 'calendar')->length === 0) {
                continue;
            }

            $collection = $this->resolveUrl($entry['href'], $home);
            if (!$this->sameOrigin($collection, $home)) {
                $this->logSkipped($home);
                continue;
            }

            $calendars[] = [
                'url' => rtrim($collection, '/').'/',
                'name' => $this->text($entry['props']['{DAV:}displayname'] ?? null) ?: 'Unnamed Calendar',
                'color' => $this->text($entry['props']['{http://apple.com/ns/ical/}calendar-color'] ?? null) ?: '#e5e7eb',
            ];
        }

        return $calendars;
    }

    /**
     * Sends a request with the given credentials to a URL that already went
     * through discovery, e.g. the import's per-collection REPORT, following
     * redirects.
     *
     * @param  array<string, string>  $headers
     */
    public function send(string $username, string $password, string $method, string $url, array $headers = [], ?string $body = null): Response
    {
        return $this->sendFollowing([$username, $password], $method, $url, $headers, $body)[0];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function sendFor(ExternalCalendar $calendar, string $method, string $url, array $headers = [], ?string $body = null): Response
    {
        $account = $calendar->account;

        return $this->request([$account->username, $account->password], $method, $url, $headers, $body);
    }

    /**
     * @param  array{0: string, 1: string}  $credentials
     * @param  array<string, string>  $headers
     */
    private function request(array $credentials, string $method, string $url, array $headers = [], ?string $body = null): Response
    {
        try {
            $pin = $this->guard->check($url);
        } catch (BlockedUrlException) {
            throw $this->unavailable($url, null, 'blocked');
        }

        $timeout = (int) config('calendars.external.timeout');
        $maxBytes = (int) config('calendars.external.max_response_bytes');
        $tooLarge = false;

        $pending = Http::withBasicAuth($credentials[0], $credentials[1])
            ->timeout($timeout)
            ->connectTimeout($timeout)
            ->withoutRedirecting()
            ->withHeaders($headers)
            ->withOptions([
                'curl' => [CURLOPT_RESOLVE => [$pin]],
                'on_headers' => function (ResponseInterface $response) use ($maxBytes, &$tooLarge) {
                    if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                        $tooLarge = true;
                        throw new ExternalCalendarUnavailableException();
                    }
                },
                'progress' => function ($downloadTotal, $downloaded) use ($maxBytes, &$tooLarge) {
                    if ($downloaded > $maxBytes) {
                        $tooLarge = true;
                        throw new ExternalCalendarUnavailableException();
                    }
                },
            ]);

        // Guzzle wraps an on_headers exception in a TransferException but lets one from progress through.
        try {
            $response = $pending->send($method, $url, $body !== null ? ['body' => $body] : []);
        } catch (ConnectionException|TransferException|ExternalCalendarUnavailableException) {
            throw $this->unavailable($url, null, $tooLarge ? 'too large' : 'connection');
        }

        if (strlen($response->body()) > $maxBytes) {
            throw $this->unavailable($url, $response->status(), 'too large');
        }

        return $response;
    }

    /**
     * Follows redirects by hand so every hop goes through the URL guard.
     *
     * @param  array{0: string, 1: string}  $credentials
     * @param  array<string, string>  $headers
     * @return array{0: Response, 1: string} the response and the URL that gave it
     */
    private function sendFollowing(array $credentials, string $method, string $url, array $headers = [], ?string $body = null): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $response = $this->request($credentials, $method, $url, $headers, $body);

            if (!$response->redirect() || !$response->header('Location')) {
                return [$response, $url];
            }

            $next = $this->resolveUrl($response->header('Location'), $url);

            // Every hop carries the Basic auth credentials, so a downgrade would send them in plaintext.
            if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' && strtolower((string) parse_url($next, PHP_URL_SCHEME)) !== 'https') {
                throw $this->unavailable($url, $response->status(), 'insecure redirect');
            }

            $url = $next;
        }

        throw $this->unavailable($url, null, 'redirects');
    }

    /**
     * The first href inside the given property of a Depth 0 PROPFIND, as an
     * absolute URL, or null when the request or the property is missing.
     *
     * @param  array{0: string, 1: string}  $credentials
     */
    private function findHref(array $credentials, string $url, string $prop, string $namespace, string $name): ?string
    {
        $body = $this->xml('<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop>'.$prop.'</d:prop></d:propfind>');

        try {
            [$response, $url] = $this->sendFollowing($credentials, 'PROPFIND', $url, ['Depth' => '0', 'Content-Type' => 'application/xml; charset=utf-8'], $body);
            $responses = $this->parseMultistatusResponse($url, $response)['responses'];
        } catch (ExternalCalendarUnavailableException) {
            return null;
        }

        foreach ($responses as $entry) {
            $element = $entry['props']['{'.$namespace.'}'.$name] ?? null;
            $href = $element?->getElementsByTagNameNS(self::NS_DAV, 'href')->item(0);

            if ($href && trim($href->textContent) !== '') {
                return $this->resolveUrl(trim($href->textContent), $url);
            }
        }

        return null;
    }

    /**
     * @return array{responses: array<int, array{href: string, status: ?int, props: array<string, \DOMElement>}>, sync_token: ?string}
     */
    private function multistatus(ExternalCalendar $calendar, string $method, string $url, string $body, string $depth): array
    {
        $response = $this->sendFor($calendar, $method, $url, ['Depth' => $depth, 'Content-Type' => 'application/xml; charset=utf-8'], $body);

        return $this->parseMultistatusResponse($url, $response);
    }

    /**
     * @return array{responses: array<int, array{href: string, status: ?int, props: array<string, \DOMElement>}>, sync_token: ?string}
     */
    private function parseMultistatusResponse(string $url, Response $response): array
    {
        if ($response->status() !== 207) {
            throw $this->unavailable($url, $response->status());
        }

        $dom = new \DOMDocument();
        if (!@$dom->loadXML($response->body(), LIBXML_NONET)) {
            throw $this->unavailable($url, 207, 'unparsable');
        }

        $responses = [];
        foreach ($dom->getElementsByTagNameNS(self::NS_DAV, 'response') as $element) {
            $href = $this->firstChild($element, self::NS_DAV, 'href');
            if (!$href) {
                continue;
            }

            $status = $this->firstChild($element, self::NS_DAV, 'status');
            $props = [];

            foreach ($element->childNodes as $propstat) {
                if (!$propstat instanceof \DOMElement || $propstat->namespaceURI !== self::NS_DAV || $propstat->localName !== 'propstat') {
                    continue;
                }

                $propstatStatus = $this->firstChild($propstat, self::NS_DAV, 'status');
                if ($propstatStatus && $this->statusCode($propstatStatus->textContent) !== 200) {
                    continue;
                }

                $prop = $this->firstChild($propstat, self::NS_DAV, 'prop');
                foreach ($prop?->childNodes ?? [] as $child) {
                    if ($child instanceof \DOMElement) {
                        $props['{'.$child->namespaceURI.'}'.$child->localName] = $child;
                    }
                }
            }

            $responses[] = [
                'href' => trim($href->textContent),
                'status' => $status ? $this->statusCode($status->textContent) : null,
                'props' => $props,
            ];
        }

        $syncToken = null;
        if ($dom->documentElement) {
            $token = $this->firstChild($dom->documentElement, self::NS_DAV, 'sync-token');
            $syncToken = $token ? trim($token->textContent) : null;
        }

        return ['responses' => $responses, 'sync_token' => $syncToken ?: null];
    }

    /**
     * @param  array<int, array{href: string, status: ?int, props: array<string, \DOMElement>}>  $responses
     * @return array<int, array{uri: string, etag: ?string, size: int, calendardata: string}>
     */
    private function objectsWithData(ExternalCalendar $calendar, array $responses): array
    {
        $objects = [];

        foreach ($responses as $response) {
            $data = $response['props']['{'.self::NS_CALDAV.'}calendar-data'] ?? null;
            if (!$data) {
                continue;
            }

            $name = $this->childName($calendar, $response['href']);
            if ($name === null) {
                continue;
            }

            $objects[] = [
                'uri' => $name,
                'etag' => $this->normalizeEtag($this->text($response['props']['{DAV:}getetag'] ?? null)),
                'size' => strlen($data->textContent),
                'calendardata' => $data->textContent,
            ];
        }

        return $objects;
    }

    /**
     * Our object URI for a remote href: its last segment, URL-decoded, when
     * the href is a direct child of the remote collection on the same host.
     * The collection itself yields null silently; anything else is logged.
     */
    public function childName(ExternalCalendar $calendar, string $href): ?string
    {
        $collection = parse_url($calendar->remote_url);
        $target = parse_url($href);

        if ($target === false) {
            $this->logSkipped($calendar->remote_url);
            return null;
        }

        if (isset($target['host']) && !$this->sameOrigin($href, $calendar->remote_url)) {
            $this->logSkipped($calendar->remote_url);
            return null;
        }

        $base = rtrim(rawurldecode($collection['path'] ?? '/'), '/').'/';
        $path = rawurldecode($target['path'] ?? '');
        if (!str_starts_with($path, '/')) {
            $path = $base.$path;
        }

        if (rtrim($path, '/').'/' === $base) {
            return null;
        }

        $name = str_starts_with($path, $base) ? substr($path, strlen($base)) : '';

        if ($name === '' || str_contains($name, '/')) {
            $this->logSkipped($calendar->remote_url);
            return null;
        }

        return $name;
    }

    public function objectUrl(ExternalCalendar $calendar, string $name): string
    {
        return rtrim($calendar->remote_url, '/').'/'.rawurlencode($name);
    }

    public function normalizeEtag(?string $etag): ?string
    {
        $etag = trim((string) $etag);

        if ($etag === '') {
            return null;
        }

        return str_starts_with($etag, '"') || str_starts_with($etag, 'W/') ? $etag : '"'.$etag.'"';
    }

    /**
     * If-Match uses strong comparison (RFC 9110 13.1.1), so a weak tag never
     * matches. A server behind a compressing proxy (nginx gzip) weakens its
     * strong ETags on GET, so the W/ prefix is dropped here only; the stored
     * tag stays as the remote sent it.
     */
    private function ifMatchHeader(string $etag): string
    {
        if (str_contains($etag, ',')) {
            return implode(', ', array_map(fn (string $tag) => $this->strongEtag(trim($tag)), explode(',', $etag)));
        }

        return (string) $this->normalizeEtag($this->strongEtag(trim($etag)));
    }

    private function strongEtag(string $etag): string
    {
        return str_starts_with($etag, 'W/') ? substr($etag, 2) : $etag;
    }

    private function isCollection(\DOMElement $resourceType): bool
    {
        return $resourceType->getElementsByTagNameNS(self::NS_DAV, 'collection')->length > 0;
    }

    private function firstChild(\DOMElement $parent, string $namespace, string $name): ?\DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->namespaceURI === $namespace && $child->localName === $name) {
                return $child;
            }
        }

        return null;
    }

    private function text(?\DOMElement $element): ?string
    {
        $text = $element ? trim($element->textContent) : '';

        return $text === '' ? null : $text;
    }

    private function statusCode(string $statusLine): ?int
    {
        return preg_match('/\s(\d{3})(\s|$)/', ' '.trim($statusLine), $matches) ? (int) $matches[1] : null;
    }

    private function utc(\DateTimeInterface $date): string
    {
        return \DateTime::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    private function xml(string $body): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'.$body;
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').$port.'/';
    }

    private function sameOrigin(string $a, string $b): bool
    {
        return strcasecmp($this->origin($a), $this->origin($b)) === 0;
    }

    private function resolveUrl(string $href, string $base): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        if (str_starts_with($href, '/')) {
            return rtrim($this->origin($base), '/').$href;
        }

        return substr($base, 0, (int) strrpos($base, '/') + 1).$href;
    }

    private function logSkipped(string $collectionUrl): void
    {
        Log::channel('dav')->warning('External calendar href skipped: not a direct child of the collection', [
            'host' => parse_url($collectionUrl, PHP_URL_HOST),
        ]);
    }

    private function unavailable(string $url, ?int $status, ?string $reason = null): ExternalCalendarUnavailableException
    {
        Log::channel('dav')->warning('External calendar request failed', [
            'host' => parse_url($url, PHP_URL_HOST),
            'status' => $status,
            'reason' => $reason,
        ]);

        return new ExternalCalendarUnavailableException();
    }
}
