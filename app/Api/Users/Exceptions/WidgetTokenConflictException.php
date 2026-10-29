<?php

namespace App\Api\Users\Exceptions;

class WidgetTokenConflictException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('widget_token_conflict');
    }
}
