<?php

namespace App\Api\Users\Exceptions;

class PersonalAccessTokenRequiredException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('personal_access_token_required');
    }
}
