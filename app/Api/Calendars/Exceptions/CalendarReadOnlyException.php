<?php

namespace App\Api\Calendars\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class CalendarReadOnlyException extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, 'This calendar is shared with you read-only.');
    }
}
