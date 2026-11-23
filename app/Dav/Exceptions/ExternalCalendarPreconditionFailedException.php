<?php

namespace App\Dav\Exceptions;

use Sabre\DAV\Exception\PreconditionFailed;

// The remote refused a write because the object changed there (412): the remote wins, the caller gets the 412 as-is.
class ExternalCalendarPreconditionFailedException extends PreconditionFailed
{
    public function __construct()
    {
        parent::__construct('The event was changed on the remote server.', 'If-Match');
    }
}
