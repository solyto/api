<?php

namespace App\Dav\Plugins;

use App\Api\Calendars\Jobs\InvalidateSharedCalendarCache;
use App\Api\Calendars\Services\CalendarService;
use App\Api\Contacts\Services\ContactService;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;

class CacheInvalidationPlugin extends ServerPlugin
{
    private const string CACHE_KEY_CALENDARS = 'calendars_v2';
    private const string CACHE_KEY_ADDRESS_BOOKS = 'address_books';
    // The job is unique until it runs, so a client syncing a burst of writes causes one run, not one per write.
    private const int SHARED_INVALIDATION_DELAY_SECONDS = 10;

    // Sharees of an owner's calendar about to be deleted, by path: sabre removes every sharee instance with the
    // owner's, so they can't be looked up after the unbind.
    /** @var array<string, array{calendarId: int, userIds: array<int, string>}> */
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

            $this->cache->flushTags([CalendarService::eventCacheTag($userId, $calendar)]);

            $isSharedInstance = (int) $instance->access > 1;
            if ($isSharedInstance || (bool) $instance->solyto_shared) {
                InvalidateSharedCalendarCache::dispatch($calendar)->delay(now()->addSeconds(self::SHARED_INVALIDATION_DELAY_SECONDS));
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
            // Logged, not thrown: the delete goes ahead, and the sharees' caches expire on their own.
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
                $this->cache->flushTags([CalendarService::eventCacheTag($userId, $pending['calendarId'])]);
            }
        } catch (\Throwable $e) {
            // Logged, not thrown: the delete already happened, and the sharees' caches expire on their own.
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

            $this->cache->forgetTagged(ContactService::contactCacheTags($userId), ContactService::contactCacheKey($userId, (int) $addressBookId));
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
