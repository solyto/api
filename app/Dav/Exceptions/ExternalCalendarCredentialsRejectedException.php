<?php

namespace App\Dav\Exceptions;

/**
 * Discovery found no calendars because the server refused the username or
 * app password (401/403).
 */
class ExternalCalendarCredentialsRejectedException extends ExternalCalendarUnavailableException
{
    public function __construct()
    {
        parent::__construct(401);
    }
}
