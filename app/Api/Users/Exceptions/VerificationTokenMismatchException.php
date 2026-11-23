<?php

namespace App\Api\Users\Exceptions;

class VerificationTokenMismatchException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('token_mismatch');
    }
}
