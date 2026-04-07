<?php

namespace App\Dav\Helpers;

use App\Api\Users\Models\User;

class DavHelper
{
    public static function getPrincipalUri(User $user): string
    {
        return 'principals/' . $user->email;
    }
}
