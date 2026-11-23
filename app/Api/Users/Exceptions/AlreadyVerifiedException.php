<?php

namespace App\Api\Users\Exceptions;

class AlreadyVerifiedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('already_verified');
    }
}
