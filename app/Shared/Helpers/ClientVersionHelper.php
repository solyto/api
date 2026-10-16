<?php

namespace App\Shared\Helpers;

use Illuminate\Http\Request;

class ClientVersionHelper
{
    public const string HEADER = 'X-Client-Version';

    private const string PATTERN = '/^\d{1,5}\.\d{1,5}\.\d{1,5}$/';

    public static function fromRequest(Request $request): ?string
    {
        $version = $request->header(self::HEADER);

        return is_string($version) && preg_match(self::PATTERN, $version) === 1 ? $version : null;
    }

    // Builds up to 1.0.15 send no header, so a missing or invalid one counts as older.
    public static function isAtLeast(Request $request, string $version): bool
    {
        $client = self::fromRequest($request);

        return $client !== null && version_compare($client, $version, '>=');
    }
}
