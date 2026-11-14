<?php

namespace App\Api\Calendars\Services;

use App\Api\Calendars\Models\ExternalCalendar;
use App\Api\Calendars\Models\ExternalCalendarAccount;
use App\Api\Users\Models\User;
use App\Dav\DTOs\CalendarDTO;
use App\Dav\Services\RemoteCalDavClient;
use App\Shared\Exceptions\BlockedUrlException;
use App\Shared\Services\UserCacheService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Accounts on the user's own CalDAV servers and the calendars connected
 * from them. A connected calendar is a native calendar row plus a link;
 * nothing calendar-level is ever written to the remote server.
 */
class ExternalCalendarService
{
    private const string CACHE_KEY_DISCOVERY = 'external_calendar_discovery';
    private const int CACHE_TTL_DISCOVERY = 600;

    public function __construct(
        private readonly CalendarService $calendars,
        private readonly RemoteCalDavClient $remote,
        private readonly UserCacheService $cache,
    ) {}

    /**
     * @return Collection<int, ExternalCalendarAccount>
     */
    public function listAccounts(User $user): Collection
    {
        return ExternalCalendarAccount::forUser($user->id)->with('calendars')->orderBy('id')->get();
    }

    /**
     * @throws ValidationException
     */
    public function createAccount(User $user, array $data): ExternalCalendarAccount
    {
        $found = $this->discover($data['server_url'], $data['username'], $data['password']);

        $account = ExternalCalendarAccount::create([
            'user_id' => $user->id,
            'server_url' => $data['server_url'],
            'username' => $data['username'],
            'password' => $data['password'],
        ]);

        $this->cache->store([self::CACHE_KEY_DISCOVERY, $user->id, $account->id], self::CACHE_TTL_DISCOVERY, $found);

        return $account->load('calendars');
    }

    /**
     * Checks the new credentials against the server before they replace the
     * old ones.
     *
     * @throws ValidationException
     */
    public function updateAccount(ExternalCalendarAccount $account, array $data): ExternalCalendarAccount
    {
        $username = $data['username'] ?? $account->username;
        $found = $this->discover($account->server_url, $username, $data['password']);

        $account->update(['username' => $username, 'password' => $data['password']]);
        $this->cache->store([self::CACHE_KEY_DISCOVERY, $account->user_id, $account->id], self::CACHE_TTL_DISCOVERY, $found);

        return $account->load('calendars');
    }

    /**
     * Removes the account and every calendar connected from it, locally
     * only: the calendars on the remote server stay as they are.
     */
    public function destroyAccount(User $user, ExternalCalendarAccount $account): void
    {
        foreach ($account->calendars as $link) {
            $calendar = $this->calendars->get($user, $link->instance_id);
            if ($calendar !== null) {
                $this->calendars->destroy($user, $calendar);
            }
        }

        $account->delete();
        $this->cache->forget([self::CACHE_KEY_DISCOVERY, $user->id, $account->id]);
    }

    /**
     * The calendar collections on the account's server.
     *
     * @return array<int, array{url: string, name: string, color: string, is_connected: bool}>
     *
     * @throws ValidationException
     */
    public function listAvailable(ExternalCalendarAccount $account): array
    {
        $found = $this->cache->remember(
            [self::CACHE_KEY_DISCOVERY, $account->user_id, $account->id],
            self::CACHE_TTL_DISCOVERY,
            fn () => $this->discover($account->server_url, $account->username, $account->password)
        );
        $connected = $account->calendars()->pluck('remote_url')->all();

        return array_map(fn (array $calendar) => $calendar + ['is_connected' => in_array($calendar['url'], $connected, true)], $found);
    }

    /**
     * Connects collections the server listed: each becomes a native
     * calendar row (named like the import, made unique with " (n)") linked
     * to the remote collection. Already connected ones are skipped. All or
     * nothing: if one fails, the calendars this call created are removed.
     *
     * @param  array<int, string>  $urls
     * @return array<int, CalendarDTO>
     *
     * @throws ValidationException
     */
    public function connect(User $user, ExternalCalendarAccount $account, array $urls): array
    {
        $available = collect($this->listAvailable($account))->keyBy('url');
        $urls = array_values(array_unique(array_map(fn (string $url) => rtrim($url, '/').'/', $urls)));

        if (array_diff($urls, $available->keys()->all()) !== []) {
            throw ValidationException::withMessages(['urls' => 'Only calendars found on the server can be connected.']);
        }

        $candidates = array_filter(array_map(fn (string $url) => $available[$url], $urls), fn (array $candidate) => !$candidate['is_connected']);
        $created = [];

        try {
            DB::transaction(function () use ($user, $account, $candidates, &$created) {
                foreach ($candidates as $candidate) {
                    $calendar = $this->calendars->create($user, [
                        'name' => $this->uniqueName($user, $candidate['name']),
                        'color' => $candidate['color'],
                    ]);
                    $created[] = $calendar;

                    ExternalCalendar::create([
                        'external_calendar_account_id' => $account->id,
                        'user_id' => $user->id,
                        'remote_url' => $candidate['url'],
                        'calendar_id' => $calendar->calendarId,
                        'instance_id' => $calendar->instanceId,
                    ]);
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

        return array_values(array_filter(array_map(fn (CalendarDTO $calendar) => $this->calendars->get($user, $calendar->instanceId), $created)));
    }

    /**
     * @return array<int, array{url: string, name: string, color: string}>
     *
     * @throws ValidationException
     */
    private function discover(string $url, string $username, string $password): array
    {
        try {
            $found = $this->remote->discover($url, $username, $password);
        } catch (BlockedUrlException) {
            throw ValidationException::withMessages(['server_url' => 'The server URL is not allowed.']);
        }

        if ($found === []) {
            throw ValidationException::withMessages(['server_url' => 'No calendars were found with these credentials.']);
        }

        return $found;
    }

    /**
     * A cleanup failure is logged, not thrown, so it cannot hide the error
     * that triggered the cleanup.
     *
     * @param  array<int, CalendarDTO>  $created
     */
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

    private function uniqueName(User $user, string $name): string
    {
        $candidate = $name;
        for ($i = 1; $this->calendars->getByName($user, $candidate) !== null; $i++) {
            $candidate = $name.' ('.$i.')';
        }

        return $candidate;
    }
}
