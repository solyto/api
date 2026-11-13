<?php

namespace App\Dav\Exceptions;

use Sabre\DAV\Exception\ServiceUnavailable;

/**
 * The remote server of an external calendar could not be reached or gave
 * no usable answer. Sabre renders it as a 503 for the one request; the API
 * leaves the calendar out of listings and reports it.
 */
class ExternalCalendarUnavailableException extends ServiceUnavailable
{
    public function __construct()
    {
        parent::__construct('The external calendar is unavailable.');
    }
}
