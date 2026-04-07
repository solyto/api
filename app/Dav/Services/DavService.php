<?php

namespace App\Dav\Services;

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
}
