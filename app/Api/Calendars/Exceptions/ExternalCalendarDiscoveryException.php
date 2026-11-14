<?php

namespace App\Api\Calendars\Exceptions;

use Exception;

class ExternalCalendarDiscoveryException extends Exception
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }
}
