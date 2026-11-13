<?php

namespace App\Dav\Exceptions;

use Sabre\DAV\Exception\PreconditionFailed;

/**
 * The remote server refused a write because the object changed there
 * (412). The remote wins; the caller gets the 412 as-is.
 */
class ExternalCalendarPreconditionFailedException extends PreconditionFailed
{
    public function __construct()
    {
        parent::__construct('The event was changed on the remote server.', 'If-Match');
    }
}
