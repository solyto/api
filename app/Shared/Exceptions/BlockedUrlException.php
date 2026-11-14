<?php

namespace App\Shared\Exceptions;

use Exception;

class BlockedUrlException extends Exception
{
    public function __construct(string $message = 'The URL is not allowed.')
    {
        parent::__construct($message);
    }
}
