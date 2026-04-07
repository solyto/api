<?php

namespace App\Dav\Factories;

use App\Dav\Auth\LaravelAuthBackend;
use Illuminate\Database\DatabaseManager;
use Sabre\CalDAV\CalendarRoot;
use Sabre\CardDAV\AddressBookRoot;
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\DAV\Server;
use Sabre\DAVACL\Plugin as AclPlugin;
use Sabre\DAVACL\PrincipalBackend\PDO as PrincipalBackend;
use Sabre\DAVACL\PrincipalCollection;
use App\Dav\Plugins\CacheInvalidationPlugin;
use Sabre\CalDAV\Backend\PDO as CalDAVBackend;
use Sabre\CalDAV\Plugin as CalDAVPlugin;
use Sabre\CardDAV\Backend\PDO as CardDAVBackend;
use Sabre\CardDAV\Plugin as CardDAVPlugin;

class DavServerFactory
{
    protected DatabaseManager $db;

    public function __construct(DatabaseManager $db)
    {
        $this->db = $db;
    }

    public function createServer(): Server
    {
        $pdo = $this->db->connection('pgsql')->getPdo();
        $principalBackend = new \Sabre\DAVACL\PrincipalBackend\PDO($pdo);
        $carddavBackend   = new \Sabre\CardDAV\Backend\PDO($pdo);
        $caldavBackend    = new \Sabre\CalDAV\Backend\PDO($pdo);

        $nodes = [
            new \Sabre\CalDAV\Principal\Collection($principalBackend),
            new \Sabre\CalDAV\CalendarRoot($principalBackend, $caldavBackend),
            new \Sabre\CardDAV\AddressBookRoot($principalBackend, $carddavBackend),
        ];

        $server = new \Sabre\DAV\Server($nodes);
        $server->setBaseUri('/');
        $server->addPlugin(new \Sabre\DAV\Auth\Plugin(new LaravelAuthBackend('DAV')));
        $server->addPlugin(new \Sabre\DAVACL\Plugin());
        $server->addPlugin(new \Sabre\DAV\Browser\Plugin());
        $server->addPlugin(new \Sabre\CalDAV\Plugin());
        $server->addPlugin(new \Sabre\CardDAV\Plugin());
        $server->addPlugin(new \Sabre\DAV\Sync\Plugin());
        $server->addPlugin(new \Sabre\DAV\Sharing\Plugin());
        $server->addPlugin(new \Sabre\CalDAV\SharingPlugin());
        $server->addPlugin(app()->make(CacheInvalidationPlugin::class));

        return $server;
    }
}
