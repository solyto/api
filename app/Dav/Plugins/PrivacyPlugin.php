<?php

namespace App\Dav\Plugins;

use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 * Keeps users from learning about each other through DAV:
 * - sabre grants read-free-busy to every authenticated user, so a
 *   free-busy-query is answered only for users who may read the calendar
 *   (its owner and accepted sharees);
 * - principal URIs carry the email address, so principal-property-search
 *   (attendee lookup) finds nobody. Solyto does not schedule over DAV.
 */
class PrivacyPlugin extends ServerPlugin
{
    private Server $server;

    public function initialize(Server $server): void
    {
        $this->server = $server;

        // Runs before the CalDAV and ACL plugins' report handlers (priority 100).
        $server->on('report', [$this, 'onReport'], 50);
    }

    public function getPluginName(): string
    {
        return 'privacy';
    }

    public function onReport(string $reportName, mixed $report, string $path): bool
    {
        if ($reportName === '{urn:ietf:params:xml:ns:caldav}free-busy-query') {
            $this->server->getPlugin('acl')->checkPrivileges($path, '{DAV:}read');

            return true;
        }

        if ($reportName === '{DAV:}principal-property-search') {
            $this->server->httpResponse->setStatus(207);
            $this->server->httpResponse->setHeader('Content-Type', 'application/xml; charset=utf-8');
            $this->server->httpResponse->setBody($this->server->generateMultiStatus([]));

            return false;
        }

        return true;
    }
}
