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

        // Without a secret file the plain env var is used, so optional secrets
        // like ntfy can be set in .env without creating files.
        if (!$filePath) {
            $value = getenv($identifier);

            return $value === false ? null : $value;
        }

        return trim(file_get_contents($filePath));
    }
}
