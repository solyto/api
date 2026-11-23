<?php

namespace App\Dav\Exceptions;

// The server refused the username or app password (401/403), during discovery or for a connected calendar.
class ExternalCalendarCredentialsRejectedException extends ExternalCalendarUnavailableException
{
    public function __construct(int $remoteStatus = 401)
    {
        parent::__construct($remoteStatus);
    }

    public function reason(): string
    {
        return 'credentials_rejected';
    }
}
