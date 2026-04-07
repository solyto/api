<?php

namespace App\Dav\Plugins;

use App\Api\Calendars\Jobs\RefreshCalendarCache;
use App\Api\Contacts\Jobs\RefreshContactsCache;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

class CacheInvalidationPlugin extends ServerPlugin
{
    private const string CACHE_KEY_CALENDARS = 'calendars';
    private const string CACHE_KEY_EVENTS = 'calendar_events';
    private const string CACHE_KEY_ADDRESS_BOOKS = 'address_books';
    private const string CACHE_KEY_CONTACTS = 'contacts';

    public function __construct(private readonly UserCacheService $cache) {}

    public function initialize(Server $server): void
    {
        $server->on('afterCreateFile',   [$this, 'onCalendarObjectWrite']);
        $server->on('afterWriteContent', [$this, 'onCalendarObjectWrite']);
        $server->on('afterUnbind',       [$this, 'onAfterUnbind']);
        $server->on('afterBind',         [$this, 'onAfterBind']);
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

    private function invalidateCalendarEvents(string $email, string $calendarUri): void
    {
        try {
            $principalUri = 'principals/' . $email;

            $calendar = DB::connection('pgsql')
                ->table('calendarinstances')
                ->where('principaluri', $principalUri)
                ->where('uri', $calendarUri)
                ->value('calendarid');

            if ($calendar === null) {
                return;
            }

            $userId = DB::table('users')
                ->where('email', $email)
                ->value('id');

            if ($userId === null) {
                return;
            }

            $this->cache->forgetByPrefix([self::CACHE_KEY_EVENTS, $userId, $calendar]);
            RefreshCalendarCache::dispatch($userId);
        } catch (\Throwable $e) {
            Log::channel('dav')->error('DAV cache invalidation failed for calendar events', [
                'email'       => $email,
                'calendarUri' => $calendarUri,
                'error'       => $e->getMessage(),
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
            RefreshCalendarCache::dispatch($userId);
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
            RefreshContactsCache::dispatch($userId);
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
            RefreshContactsCache::dispatch($userId);
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
