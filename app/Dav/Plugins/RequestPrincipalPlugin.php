<?php

namespace App\Dav\Plugins;

use App\Dav\Backend\AppCalendarsPDO;
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

class RequestPrincipalPlugin extends ServerPlugin
{
    // After the auth plugin (10) and before the ACL plugin (20), whose privilege check already loads the calendar.
    private const int PRIORITY = 15;

    private Server $server;

    public function __construct(private readonly AppCalendarsPDO $backend) {}

    public function initialize(Server $server): void
    {
        $this->server = $server;
        $server->on('beforeMethod:*', [$this, 'capture'], self::PRIORITY);
    }

    public function getPluginName(): string
    {
        return 'request-principal';
    }

    public function capture(): void
    {
        $auth = $this->server->getPlugin('auth');

        $this->backend->setRequestPrincipal($auth instanceof AuthPlugin ? $auth->getCurrentPrincipal() : null);
    }
}
