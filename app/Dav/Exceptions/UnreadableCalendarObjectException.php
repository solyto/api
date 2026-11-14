<?php

namespace App\Dav\Exceptions;

use Sabre\DAV\Exception;

/**
 * A stored calendar object cannot be parsed, so it cannot be edited in
 * place. Only external calendars can hold such data: sabre never validated
 * what their server stores.
 */
class UnreadableCalendarObjectException extends Exception
{
    public function __construct()
    {
        parent::__construct('The calendar object cannot be read.');
    }

    public function getHTTPCode()
    {
        return 422;
    }
}
