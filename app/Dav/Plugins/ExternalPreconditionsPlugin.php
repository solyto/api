<?php

namespace App\Dav\Plugins;

use App\Dav\Backend\AppCalendarsPDO;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;

/**
 * Sabre's backend API carries no request headers. This hands the client's
 * If-Match to the calendar backend, which sends it on to the remote server
 * of an external calendar, so a remote change between sabre's own
 * precondition check and the write still ends in a 412 (the remote wins).
 * Native calendars ignore it.
 */
class ExternalPreconditionsPlugin extends ServerPlugin
{
    public function __construct(private readonly AppCalendarsPDO $backend) {}

    public function initialize(Server $server): void
    {
        $server->on('beforeMethod:PUT', [$this, 'capture']);
        $server->on('beforeMethod:DELETE', [$this, 'capture']);
    }

    public function getPluginName(): string
    {
        return 'external-preconditions';
    }

    public function capture(RequestInterface $request): void
    {
        $this->backend->setRequestPreconditions($request->getHeader('If-Match'));
    }
}
