<?php

namespace App\Api\Users\Exceptions;

class InvalidDeletionTokenException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('invalid_token');
    }
}
