<?php

namespace App\Dav\Plugins;

use App\Api\Calendars\Jobs\InvalidateSharedCalendarCache;
use App\Api\Calendars\Jobs\RefreshCalendarCache;
use App\Api\Contacts\Jobs\RefreshContactsCache;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;

class CacheInvalidationPlugin extends ServerPlugin
{
    private const string CACHE_KEY_CALENDARS = 'calendars';
    private const string CACHE_KEY_EVENTS = 'calendar_events';
    private const string CACHE_KEY_ADDRESS_BOOKS = 'address_books';
    private const string CACHE_KEY_CONTACTS = 'contacts';

    /**
     * Sharees of an owner's calendar that is about to be deleted, keyed by
     * path. Sabre removes every sharee instance with the owner's, so they
     * can't be looked up after the unbind.
     *
     * @var array<string, array{calendarId: int, userIds: array<int, string>}>
     */
    private array $pendingSharees = [];

    public function __construct(private readonly UserCacheService $cache) {}

    public function initialize(Server $server): void
    {
        $server->on('afterCreateFile',   [$this, 'onCalendarObjectWrite']);
        $server->on('afterWriteContent', [$this, 'onCalendarObjectWrite']);
        // Default priority, so it runs after the ACL check (priority 20).
        $server->on('beforeUnbind',      [$this, 'onBeforeUnbind']);
        $server->on('afterUnbind',       [$this, 'onAfterUnbind']);
        $server->on('afterBind',         [$this, 'onAfterBind']);
        $server->on('afterMethod:PROPPATCH', [$this, 'onAfterPropPatch']);
    }

    public function getPluginName(): string
    {
        return 'cache-invalidation';
    }

    public function onCalendarObjectWrite(string $uri): void
    {
        $parts = $this->parsePath($uri);

        if (count($parts) === 4 && $parts[0] === 'calendars') {
            $this->invalidateCalendarEvents($parts[1], $parts[2]);
        }

        if (count($parts) === 4 && $parts[0] === 'addressbooks') {
            $this->invalidateContacts($parts[1], $parts[2]);
        }
    }

    public function onBeforeUnbind(string $path): void
    {
        $parts = $this->parsePath($path);

        if (count($parts) === 3 && $parts[0] === 'calendars') {
            $this->collectSharees($path, $parts[1], $parts[2]);
        }
    }

    public function onAfterUnbind(string $path): void
    {
        $parts = $this->parsePath($path);

        if (empty($parts)) {
            return;
        }

        if ($parts[0] === 'calendars') {
            if (count($parts) === 4) {
                $this->invalidateCalendarEvents($parts[1], $parts[2]);
            } elseif (count($parts) === 3) {
                $this->invalidateCalendarEvents($parts[1], $parts[2]);
                $this->invalidateCalendarsForEmail($parts[1]);
                $this->invalidateSharees($path);
            }
        }

        if ($parts[0] === 'addressbooks') {
            if (count($parts) === 4) {
                $this->invalidateContacts($parts[1], $parts[2]);
            } elseif (count($parts) === 3) {
                $this->invalidateContacts($parts[1], $parts[2]);
                $this->invalidateAddressBooksForEmail($parts[1]);
            }
        }
    }

    public function onAfterBind(string $uri): void
    {
        $parts = $this->parsePath($uri);

        if (count($parts) === 3 && $parts[0] === 'calendars') {
            $this->invalidateCalendarsForEmail($parts[1]);
        }

        if (count($parts) === 3 && $parts[0] === 'addressbooks') {
            $this->invalidateAddressBooksForEmail($parts[1]);
        }
    }

    public function onAfterPropPatch(RequestInterface $request): void
    {
        $parts = $this->parsePath($request->getPath());

        if (count($parts) === 3 && $parts[0] === 'calendars') {
            $this->invalidateCalendarsForEmail($parts[1]);
        }

        if (count($parts) === 3 && $parts[0] === 'addressbooks') {
            $this->invalidateAddressBooksForEmail($parts[1]);
        }
    }

    private function invalidateCalendarEvents(string $email, string $calendarUri): void
    {
        try {
            $principalUri = 'principals/' . $email;

            $instance = DB::connection('pgsql')
                ->table('calendarinstances')
                ->where('principaluri', $principalUri)
                ->where('uri', $calendarUri)
                ->first(['calendarid', 'access', 'solyto_shared']);

            if ($instance === null) {
                return;
            }

            $calendar = (int) $instance->calendarid;

            $userId = DB::table('users')
                ->where('email', $email)
                ->value('id');

            if ($userId === null) {
                return;
            }

            $this->cache->forgetByPrefix([self::CACHE_KEY_EVENTS, $userId, $calendar]);
            RefreshCalendarCache::dispatch($userId)->delay(now()->addSeconds(10));

            if ((int) $instance->access > 1 || (bool) $instance->solyto_shared) {
                InvalidateSharedCalendarCache::dispatch($calendar)->delay(now()->addSeconds(10));
            }
        } catch (\Throwable $e) {
            Log::channel('dav')->error('DAV cache invalidation failed for calendar events', [
                'email'       => $email,
                'calendarUri' => $calendarUri,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    private function collectSharees(string $path, string $email, string $calendarUri): void
    {
        unset($this->pendingSharees[$path]);

        try {
            $calendarId = DB::connection('pgsql')
                ->table('calendarinstances')
                ->where('principaluri', 'principals/' . $email)
                ->where('uri', $calendarUri)
                ->where('access', 1)
                ->value('calendarid');

            if ($calendarId === null) {
                return;
            }

            $emails = DB::connection('pgsql')
                ->table('calendarinstances')
                ->where('calendarid', $calendarId)
                ->where('access', '>', 1)
                ->pluck('principaluri')
                ->map(fn (string $principalUri) => str_replace('principals/', '', $principalUri));

            if ($emails->isEmpty()) {
                return;
            }

            $this->pendingSharees[$path] = [
                'calendarId' => (int) $calendarId,
                'userIds'    => DB::table('users')->whereIn('email', $emails)->pluck('id')->all(),
            ];
        } catch (\Throwable $e) {
            Log::channel('dav')->error('DAV cache invalidation failed to collect calendar sharees', [
                'email'       => $email,
                'calendarUri' => $calendarUri,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    private function invalidateSharees(string $path): void
    {
        $pending = $this->pendingSharees[$path] ?? null;
        unset($this->pendingSharees[$path]);

        if ($pending === null) {
            return;
        }

        try {
            foreach ($pending['userIds'] as $userId) {
                $this->cache->forget([self::CACHE_KEY_CALENDARS, $userId]);
                $this->cache->forgetByPrefix([self::CACHE_KEY_EVENTS, $userId, $pending['calendarId']]);
                RefreshCalendarCache::dispatch($userId)->delay(now()->addSeconds(10));
            }
        } catch (\Throwable $e) {
            Log::channel('dav')->error('DAV cache invalidation failed for calendar sharees', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function invalidateCalendarsForEmail(string $email): void
    {
        try {
            $userId = DB::table('users')
                ->where('email', $email)
                ->value('id');

            if ($userId === null) {
                return;
            }

            $this->cache->forget([self::CACHE_KEY_CALENDARS, $userId]);
            RefreshCalendarCache::dispatch($userId)->delay(now()->addSeconds(10));
        } catch (\Throwable $e) {
            Log::channel('dav')->error('DAV cache invalidation failed for calendars', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function invalidateContacts(string $email, string $addressBookUri): void
    {
        try {
            $principalUri = 'principals/' . $email;

            $addressBookId = DB::connection('pgsql')
                ->table('addressbooks')
                ->where('principaluri', $principalUri)
                ->where('uri', $addressBookUri)
                ->value('id');

            if ($addressBookId === null) {
                return;
            }

            $userId = DB::table('users')
                ->where('email', $email)
                ->value('id');

            if ($userId === null) {
                return;
            }

            $this->cache->forget([self::CACHE_KEY_CONTACTS, $userId, $addressBookId]);
            RefreshContactsCache::dispatch($userId)->delay(now()->addSeconds(10));
        } catch (\Throwable $e) {
            Log::channel('dav')->error('DAV cache invalidation failed for contacts', [
                'email'          => $email,
                'addressBookUri' => $addressBookUri,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    private function invalidateAddressBooksForEmail(string $email): void
    {
        try {
            $userId = DB::table('users')
                ->where('email', $email)
                ->value('id');

            if ($userId === null) {
                return;
            }

            $this->cache->forget([self::CACHE_KEY_ADDRESS_BOOKS, $userId]);
            RefreshContactsCache::dispatch($userId)->delay(now()->addSeconds(10));
        } catch (\Throwable $e) {
            Log::channel('dav')->error('DAV cache invalidation failed for address books', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function parsePath(string $path): array
    {
        return explode('/', trim($path, '/'));
    }
}
