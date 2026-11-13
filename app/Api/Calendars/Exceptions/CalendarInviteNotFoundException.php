<?php

namespace App\Api\Calendars\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class CalendarInviteNotFoundException extends HttpException
{
    public function __construct()
    {
        parent::__construct(404, 'Invite does not exist');
    }
}
