<?php

namespace App\Dav\Exceptions;

use Sabre\DAV\Exception;

// Only external calendars can hold such data: sabre never validated what their server stores.
class UnreadableCalendarObjectException extends Exception
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('The calendar object cannot be read.', 0, $previous);
    }

    public function getHTTPCode()
    {
        return 422;
    }
}
