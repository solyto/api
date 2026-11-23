<?php

namespace App\Api\Users\Exceptions;

class VerificationTokenExpiredException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('token_expired');
    }
}
