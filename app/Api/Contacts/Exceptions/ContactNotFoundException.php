<?php

namespace App\Api\Contacts\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class ContactNotFoundException extends HttpException
{
    public function __construct()
    {
        parent::__construct(404, 'Contact does not exist');
    }
}
