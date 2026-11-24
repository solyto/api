<?php

namespace App\Api\Calendars\Services;

use App\Api\Calendars\Exceptions\ExternalCalendarDiscoveryException;
use App\Api\Calendars\Models\ExternalCalendar;
use App\Api\Calendars\Models\ExternalCalendarAccount;
use App\Api\Users\Models\User;
use App\Dav\DTOs\CalendarDTO;
use App\Dav\Exceptions\ExternalCalendarCredentialsRejectedException;
use App\Dav\Exceptions\ExternalCalendarUnavailableException;
use App\Dav\Services\RemoteCalDavClient;
use App\Shared\Exceptions\BlockedUrlException;
use App\Shared\Exceptions\UnresolvableHostException;
use App\Shared\Services\UserCacheService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

// A connected calendar is a native calendar row plus a link; nothing calendar-level is ever written to the remote server.
class ExternalCalendarService
{
    private const string CACHE_KEY_DISCOVERY = 'external_calendar_discovery';
    private const int CACHE_TTL_DISCOVERY = 600;

    public function __construct(
        private readonly CalendarService $calendars,
        private readonly RemoteCalDavClient $remote,
        private readonly UserCacheService $cache,
    ) {}

    /** @return Collection<int, ExternalCalendarAccount> */
    public function listAccounts(User $user): Collection
    {
        return ExternalCalendarAccount::forUser($user->id)->with('calendars')->orderBy('id')->get();
    }

    /** @throws ExternalCalendarDiscoveryException */
    public function createAccount(User $user, array $data): ExternalCalendarAccount
    {
        $found = $this->discover($data['server_url'], $data['username'], $data['password']);

        $account = ExternalCalendarAccount::create([
            'user_id' => $user->id,
            'server_url' => $data['server_url'],
            'username' => $data['username'],
            'password' => $data['password'],
        ]);

        $this->cache->storeTagged($this->discoveryCacheTags($user->id), [self::CACHE_KEY_DISCOVERY, $user->id, $account->id], self::CACHE_TTL_DISCOVERY, $found);

        return $account->load('calendars');
    }

    // The server checks the new credentials before they replace the old ones; its calendars are then
    // asked again right away instead of staying flagged unavailable for the rest of the TTL.
    /** @throws ExternalCalendarDiscoveryException */
    public function updateAccount(ExternalCalendarAccount $account, array $data): ExternalCalendarAccount
    {
        $username = $data['username'] ?? $account->username;
        $found = $this->discover($account->server_url, $username, $data['password']);

        $account->update(['username' => $username, 'password' => $data['password']]);
        $this->cache->storeTagged($this->discoveryCacheTags($account->user_id), [self::CACHE_KEY_DISCOVERY, $account->user_id, $account->id], self::CACHE_TTL_DISCOVERY, $found);

        foreach ($account->calendars as $link) {
            $this->calendars->forgetRemoteState($account->user_id, $link->calendar_id);
        }

        return $account->load('calendars');
    }

    // Locally only: the calendars on the remote server stay as they are.
    public function destroyAccount(User $user, ExternalCalendarAccount $account): void
    {
        foreach ($account->calendars as $link) {
            $calendar = $this->calendars->get($user, $link->instance_id);
            if ($calendar !== null) {
                $this->calendars->destroy($user, $calendar);
            }
        }

        $account->delete();
        $this->cache->forgetTagged($this->discoveryCacheTags($user->id), [self::CACHE_KEY_DISCOVERY, $user->id, $account->id]);
    }

    /**
     * @return array<int, array{url: string, name: string, color: string, is_connected: bool}>
     *
     * @throws ExternalCalendarDiscoveryException
     */
    public function listAvailable(ExternalCalendarAccount $account): array
    {
        $found = $this->cache->rememberTagged(
            $this->discoveryCacheTags($account->user_id),
            [self::CACHE_KEY_DISCOVERY, $account->user_id, $account->id],
            self::CACHE_TTL_DISCOVERY,
            fn () => $this->discover($account->server_url, $account->username, $account->password)
        );
        $connected = $account->calendars()->pluck('remote_url')->all();

        return array_map(fn (array $calendar) => $calendar + ['is_connected' => in_array($calendar['url'], $connected, true)], $found);
    }

    // All or nothing: if one collection fails, the calendars this call created are removed.
    /**
     * @param  array<int, string>  $urls
     * @return array<int, CalendarDTO>
     *
     * @throws ValidationException
     * @throws ExternalCalendarDiscoveryException
     */
    public function connect(User $user, ExternalCalendarAccount $account, array $urls): array
    {
        $available = collect($this->listAvailable($account))->keyBy('url');
        $withSlash = array_map(fn (string $url) => rtrim($url, '/').'/', $urls);
        $urls = array_values(array_unique($withSlash));

        $unlisted = array_diff($urls, $available->keys()->all());
        if ($unlisted !== []) {
            throw ValidationException::withMessages(['urls' => 'Only calendars found on the server can be connected.']);
        }

        $requested = array_map(fn (string $url) => $available[$url], $urls);
        $candidates = array_filter($requested, fn (array $candidate) => !$candidate['is_connected']);
        $created = [];

        try {
            DB::transaction(function () use ($user, $account, $candidates, &$created) {
                foreach ($candidates as $candidate) {
                    $calendar = $this->calendars->create($user, $this->calendarData($user, $candidate));
                    $created[] = $calendar;

                    try {
                        ExternalCalendar::create([
                            'external_calendar_account_id' => $account->id,
                            'user_id' => $user->id,
                            'remote_url' => $candidate['url'],
                            // The unique key is on the hash: the URL column is too long to index on older MariaDB.
                            'remote_url_hash' => hash('sha256', $candidate['url']),
                            'calendar_id' => $calendar->calendarId,
                            'instance_id' => $calendar->instanceId,
                        ]);
                    } catch (UniqueConstraintViolationException) {
                        // A concurrent connect linked this collection first: treated as already connected.
                        array_pop($created);
                        $this->removeCreated($user, [$calendar]);
                    }
                }
            });
        } catch (\Throwable $e) {
            // Rethrown: the catch only undoes the DAV calendars, which live on the pgsql connection outside the transaction.
            $this->removeCreated($user, $created);

            throw $e;
        }

        foreach ($created as $calendar) {
            $this->calendars->markExternal($user, $calendar);
        }

        $connected = array_map(fn (CalendarDTO $calendar) => $this->calendars->get($user, $calendar->instanceId), $created);

        return array_values(array_filter($connected));
    }

    /**
     * @param  array{name: string, color: string}  $candidate
     * @return array{name: string, color?: string}
     */
    private function calendarData(User $user, array $candidate): array
    {
        $data = ['name' => $this->uniqueName($user, $candidate['name'])];
        if ($this->isStorableColor($candidate['color'])) {
            $data['color'] = $candidate['color'];
        }

        return $data;
    }

    /**
     * @return array<int, array{url: string, name: string, color: string}>
     *
     * @throws ExternalCalendarDiscoveryException
     */
    private function discover(string $url, string $username, #[\SensitiveParameter] string $password): array
    {
        // Each subclass before its parent: rejected credentials are an unavailable server, an unresolvable host a blocked URL.
        try {
            $found = $this->remote->discover($url, $username, $password);
        } catch (ExternalCalendarCredentialsRejectedException) {
            throw new ExternalCalendarDiscoveryException('The server rejected the username or app password.', 'external_credentials_rejected');
        } catch (UnresolvableHostException|ExternalCalendarUnavailableException) {
            throw new ExternalCalendarDiscoveryException('The server could not be reached or did not answer as a CalDAV server.', 'external_server_unreachable');
        } catch (BlockedUrlException) {
            throw new ExternalCalendarDiscoveryException('The server URL is not allowed.', 'external_url_not_allowed');
        }

        if ($found === []) {
            throw new ExternalCalendarDiscoveryException('No calendars were found on the server.', 'external_no_calendars');
        }

        return $found;
    }

    // A cleanup failure is logged, not thrown, so it cannot hide the error that triggered the cleanup.
    /** @param  array<int, CalendarDTO>  $created */
    private function removeCreated(User $user, array $created): void
    {
        foreach ($created as $calendar) {
            try {
                $this->calendars->destroy($user, $calendar);
            } catch (\Throwable $e) {
                Log::error('Could not remove a calendar after a failed external connect', [
                    'user_id' => $user->id,
                    'instance_id' => $calendar->instanceId,
                    'exception' => $e,
                ]);
            }
        }
    }

    /** @return array<int, string> */
    private function discoveryCacheTags(string $userId): array
    {
        return [UserCacheService::userTag($userId)];
    }

    private function uniqueName(User $user, string $name): string
    {
        $candidate = mb_substr($name, 0, CalendarService::MAX_NAME_LENGTH);
        $i = 1;
        while ($this->nameTaken($user, $candidate)) {
            $suffix = ' ('.$i.')';
            $candidate = mb_substr($name, 0, CalendarService::MAX_NAME_LENGTH - mb_strlen($suffix)).$suffix;
            $i++;
        }

        return $candidate;
    }

    private function nameTaken(User $user, string $name): bool
    {
        return $this->calendars->getByName($user, $name) !== null;
    }

    // calendarinstances.calendarcolor is VARCHAR(10) on pgsql; anything else the remote sends gets the default colour.
    private function isStorableColor(string $color): bool
    {
        return preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color) === 1;
    }
}
