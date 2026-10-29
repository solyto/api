<?php

namespace App\Api\Users\Exceptions;

class EmailUnverifiedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('email_unverified');
    }
}
