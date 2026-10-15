<?php

namespace App\Api\Calendars\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class CalendarNotFoundException extends HttpException
{
    public function __construct()
    {
        parent::__construct(404, 'Calendar does not exist');
    }
}
