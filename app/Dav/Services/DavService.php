<?php

namespace App\Dav\Services;

use App\Api\Users\Models\User;
use App\Dav\Helpers\DavHelper;
use App\Dav\Models\CalendarSubscription;
use App\Shared\Services\UserCacheService;
use Sabre\CalDAV\Backend\PDO as CalDavBackend;
use Sabre\CardDAV\Backend\PDO as CardDavBackend;
use Illuminate\Support\Facades\DB;

class DavService
{
    private Calendars $calendars;
    private AddressBooks $addressBooks;
    private Principals $principals;
    private ImportService $import;

    public function __construct(private readonly UserCacheService $cache)
    {
        $pdo = DB::connection('pgsql')->getPdo();

        $this->calendars = new Calendars($pdo);
        $this->addressBooks = new AddressBooks($pdo);
        $this->principals = new Principals($pdo);
        $this->import = new ImportService($this->calendars, $this->addressBooks, $this->cache);
    }

    public function calendars(): Calendars
    {
        return $this->calendars;
    }

    public function addressBooks(): AddressBooks
    {
        return $this->addressBooks;
    }

    public function principals(): Principals
    {
        return $this->principals;
    }

    public function import(): ImportService
    {
        return $this->import;
    }

    /**
     * Removes every DAV row the user owns on pgsql: owned calendars (with
     * their objects, changes and every sharee instance), the instance rows
     * of calendars shared with the user, address books, subscriptions,
     * scheduling objects, property storage and the principal with its
     * proxies and group memberships.
     *
     * @return array<string, int|bool>
     */
    public function deleteForUser(User $user): array
    {
        $principalUri = DavHelper::getPrincipalUri($user);

        $calendars = $this->calendars->deleteForUser($user);
        $addressBooks = $this->addressBooks->deleteForUser($user);

        $subscriptions = CalendarSubscription::forPrincipal($principalUri)->delete();

        $schedulingObjects = DB::connection('pgsql')
            ->table('schedulingobjects')
            ->where('principaluri', $principalUri)
            ->delete();

        $principal = $this->principals->delete($user->email);

        return [
            'owned_calendars' => $calendars['owned'],
            'shared_instances' => $calendars['shared'],
            'address_books' => $addressBooks,
            'calendar_subscriptions' => $subscriptions,
            'scheduling_objects' => $schedulingObjects,
            'principal' => $principal,
        ];
    }
}
