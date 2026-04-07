<?php

namespace App\Dav\Enums;

enum DavOperationEnum: int
{
    case CREATE = 1;
    case UPDATE = 2;
    case DELETE = 3;
}
