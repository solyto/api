<?php

namespace App\Shared\Exceptions;

// A BlockedUrlException, so a caller that does not tell the two apart still refuses the URL; callers that report the
// reason catch this one first.
class UnresolvableHostException extends BlockedUrlException
{
    public function __construct()
    {
        parent::__construct('The host could not be resolved.');
    }
}
