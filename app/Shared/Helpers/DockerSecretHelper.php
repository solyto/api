<?php

namespace App\Shared\Helpers;

class DockerSecretHelper
{
    public static function get(string $identifier): ?string
    {
        if (getenv('APP_DEBUG') === "true") {
            return getenv($identifier);
        }

        $filePath = getenv($identifier . '_FILE');

        if (!$filePath) {
            return null;
        }

        return trim(file_get_contents($filePath));
    }
}
