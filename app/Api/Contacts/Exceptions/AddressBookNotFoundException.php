<?php

namespace App\Api\Contacts\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class AddressBookNotFoundException extends HttpException
{
    public function __construct()
    {
        parent::__construct(404, 'Address Book does not exist');
    }
}
