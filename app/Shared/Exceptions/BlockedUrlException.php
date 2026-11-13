<?php

namespace App\Shared\Exceptions;

use Exception;

class BlockedUrlException extends Exception
{
    public function __construct()
    {
        parent::__construct('The URL is not allowed.');
    }
}
