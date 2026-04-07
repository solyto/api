<?php

namespace App\Dav\Services;

use App\Shared\Services\UserCacheService;

class ImportService
{
    private CalendarImport $calendars;
    private ContactImport $addressBooks;

    public function __construct(
        Calendars $calendars,
        AddressBooks $addressBooks,
        UserCacheService $cache
    )
    {
        $this->calendars = new CalendarImport($calendars, $cache);
        $this->addressBooks = new ContactImport($addressBooks, $cache);
    }

    public function calendars(): CalendarImport
    {
        return $this->calendars;
    }

    public function addressBooks(): ContactImport
    {
        return $this->addressBooks;
    }
}
