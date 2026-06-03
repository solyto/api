<?php

namespace App\Shared\Enums;

enum AuthPlatformEnum: string
{
    case WEB = 'web';
    case MOBILE = 'mobile';
    case DESKTOP = 'desktop';

    public function tokenExpiryDays(): int
    {
        return match($this) {
            self::MOBILE, self::DESKTOP => 90,
            self::WEB => 7,
        };
    }
}
