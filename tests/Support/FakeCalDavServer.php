<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * An in-memory CalDAV collection behind Http::fake(), standing in for the
 * user's own server behind an external calendar. It answers GET, PUT
 * (If-Match / If-None-Match), DELETE, PROPFIND (Depth 0 and 1),
 * calendar-query, calendar-multiget and sync-collection.
 */
class FakeCalDavServer
{
    public const string ORIGIN = 'https://dav.example.com';

    /** @var array<string, array{data: string, etag: string}> */
    public array $objects = [];

    /** @var array<int, array<string, bool>> sync token => [name => deleted] changed after it */
    private array $changes = [];

    private int $token = 1;

    public bool $down = false;

    public bool $supportsSync = true;

    /** @var array<int, string> hrefs added to every multistatus listing */
    public array $extraHrefs = [];

    /** @var array<int, Request> */
    public array $requests = [];

    public string $displayName = 'Work';

    /** @var array{0: string, 1: string}|null username and password discovery accepts; any when null */
    public ?array $credentials = null;

    public function __construct(public string $path = '/remote.php/dav/calendars/alice/work/') {}

    public function url(): string
    {
        return self::ORIGIN.$this->path;
    }

    public function put(string $name, string $data): string
    {
        $etag = '"'.md5($data.microtime()).'"';
        $this->objects[$name] = ['data' => $data, 'etag' => $etag];
        $this->changed($name, false);

        return $etag;
    }

    public function remove(string $name): void
    {
        unset($this->objects[$name]);
        $this->changed($name, true);
    }

    public function fake(): self
    {
        Http::fake(fn (Request $request) => $this->handle($request));

        return $this;
    }

    public function handle(Request $request): mixed
    {
        $this->requests[] = $request;

        if ($this->down) {
            return Http::response('', 503);
        }

        $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));

        if ($request->method() === 'PROPFIND' && ($discovery = $this->discovery($request, $path)) !== null) {
            return $discovery;
        }

        if (rtrim($path, '/').'/' === $this->path) {
            return match ($request->method()) {
                'PROPFIND' => $request->header('Depth')[0] === '0' ? $this->collectionProps() : $this->listing(),
                'REPORT' => $this->report($request->body()),
                default => Http::response('', 405),
            };
        }

        if (!str_starts_with($path, $this->path)) {
            return Http::response('', 404);
        }

        $name = substr($path, strlen($this->path));
        $object = $this->objects[$name] ?? null;
        $ifMatch = $request->header('If-Match')[0] ?? null;

        if ($ifMatch !== null && ($object === null || $object['etag'] !== $ifMatch)) {
            return Http::response('', 412);
        }

        return match ($request->method()) {
            'GET' => $object ? Http::response($object['data'], 200, ['ETag' => $object['etag'], 'Content-Type' => 'text/calendar']) : Http::response('', 404),
            'PUT' => $this->handlePut($request, $name, $object),
            'DELETE' => $object ? $this->handleDelete($name) : Http::response('', 404),
            default => Http::response('', 405),
        };
    }

    /**
     * @param  array{data: string, etag: string}|null  $object
     */
    private function handlePut(Request $request, string $name, ?array $object): mixed
    {
        if ($object !== null && ($request->header('If-None-Match')[0] ?? null) === '*') {
            return Http::response('', 412);
        }

        $etag = $this->put($name, $request->body());

        return Http::response('', $object ? 204 : 201, ['ETag' => $etag]);
    }

    private function handleDelete(string $name): mixed
    {
        $this->remove($name);

        return Http::response('', 204);
    }

    /**
     * Nextcloud-style discovery: the server root names the principal, the
     * principal names the home, the home lists this collection.
     */
    private function discovery(Request $request, string $path): mixed
    {
        $home = dirname($this->path).'/';
        $principal = '/remote.php/dav/principals/users/alice/';

        if (!in_array($path, ['/', '/remote.php/dav/', $principal, $home], true)) {
            return null;
        }

        if ($this->credentials !== null && !$request->hasHeader('Authorization', 'Basic '.base64_encode(implode(':', $this->credentials)))) {
            return Http::response('', 401);
        }

        return match ($path) {
            '/', '/remote.php/dav/' => $this->multistatus($this->response($path, '<d:current-user-principal><d:href>'.$principal.'</d:href></d:current-user-principal>')),
            $principal => $this->multistatus($this->response($principal, '<c:calendar-home-set><d:href>'.$home.'</d:href></c:calendar-home-set>')),
            default => $this->multistatus(
                $this->response($home, '<d:resourcetype><d:collection/></d:resourcetype>')
                .$this->response($this->path, '<d:displayname>'.htmlspecialchars($this->displayName, ENT_XML1).'</d:displayname><d:resourcetype><d:collection/><c:calendar/></d:resourcetype><x:calendar-color xmlns:x="http://apple.com/ns/ical/">#FF0000</x:calendar-color>')
            ),
        };
    }

    private function collectionProps(): mixed
    {
        $props = '<cs:getctag>ctag-'.$this->token.'</cs:getctag>';
        if ($this->supportsSync) {
            $props .= '<d:sync-token>'.$this->syncToken().'</d:sync-token>'
                .'<d:supported-report-set><d:supported-report><d:report><d:sync-collection/></d:report></d:supported-report></d:supported-report-set>';
        }

        return $this->multistatus($this->response($this->path, $props));
    }

    private function listing(): mixed
    {
        $body = $this->response($this->path, '<d:resourcetype><d:collection/><c:calendar/></d:resourcetype>');
        foreach ($this->objects as $name => $object) {
            $body .= $this->response($this->href($name), '<d:resourcetype/><d:getetag>'.$object['etag'].'</d:getetag><d:getcontentlength>'.strlen($object['data']).'</d:getcontentlength>');
        }

        return $this->multistatus($body);
    }

    private function report(string $body): mixed
    {
        $dom = new \DOMDocument();
        $dom->loadXML($body);
        $root = $dom->documentElement->localName;

        if ($root === 'sync-collection') {
            return $this->sync($dom);
        }

        if ($root === 'calendar-multiget') {
            $names = [];
            foreach ($dom->getElementsByTagNameNS('DAV:', 'href') as $href) {
                $names[] = substr(rawurldecode($href->textContent), strlen($this->path));
            }
        } else {
            $names = array_keys($this->objects);
        }

        $out = '';
        foreach ($names as $name) {
            if (isset($this->objects[$name])) {
                $out .= $this->dataResponse($name);
            }
        }

        return $this->multistatus($out);
    }

    private function sync(\DOMDocument $dom): mixed
    {
        if (!$this->supportsSync) {
            return Http::response('', 403);
        }

        $token = trim($dom->getElementsByTagNameNS('DAV:', 'sync-token')->item(0)?->textContent ?? '');
        $out = '';

        if ($token === '') {
            foreach (array_keys($this->objects) as $name) {
                $out .= $this->response($this->href($name), '<d:getetag>'.$this->objects[$name]['etag'].'</d:getetag>');
            }
        } else {
            $since = (int) substr($token, strlen('https://dav.example.com/sync/'));
            if ($since < 1 || $since > $this->token) {
                return Http::response('<d:error xmlns:d="DAV:"><d:valid-sync-token/></d:error>', 403);
            }

            $changed = [];
            foreach ($this->changes as $at => $names) {
                if ($at >= $since) {
                    $changed = array_merge($changed, $names);
                }
            }

            foreach ($changed as $name => $deleted) {
                $out .= $deleted
                    ? '<d:response><d:href>'.$this->href($name).'</d:href><d:status>HTTP/1.1 404 Not Found</d:status></d:response>'
                    : $this->response($this->href($name), '<d:getetag>'.$this->objects[$name]['etag'].'</d:getetag>');
            }
        }

        return $this->multistatus($out.'<d:sync-token>'.$this->syncToken().'</d:sync-token>');
    }

    public function syncToken(): string
    {
        return 'https://dav.example.com/sync/'.$this->token;
    }

    private function changed(string $name, bool $deleted): void
    {
        $this->changes[$this->token][$name] = $deleted;
        $this->token++;
    }

    private function dataResponse(string $name): string
    {
        return $this->response($this->href($name), '<d:getetag>'.$this->objects[$name]['etag'].'</d:getetag><c:calendar-data>'.htmlspecialchars($this->objects[$name]['data'], ENT_XML1).'</c:calendar-data>');
    }

    private function href(string $name): string
    {
        return $this->path.rawurlencode($name);
    }

    private function response(string $href, string $props): string
    {
        return '<d:response><d:href>'.htmlspecialchars($href, ENT_XML1).'</d:href><d:propstat><d:prop>'.$props.'</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
    }

    private function multistatus(string $body): mixed
    {
        $extra = implode('', array_map(
            fn (string $href) => $this->response($href, '<d:getetag>"x"</d:getetag><c:calendar-data>'.htmlspecialchars("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", ENT_XML1).'</c:calendar-data>'),
            $this->extraHrefs,
        ));

        return Http::response(
            '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/">'.$body.$extra.'</d:multistatus>',
            207,
            ['Content-Type' => 'application/xml; charset=utf-8'],
        );
    }
}
