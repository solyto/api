<?php

namespace App\Api\Users\Enums;

enum AccountDeletionStatus: string
{
    case EmailSent = 'email_sent';
    case Deleted = 'deleted';
}
