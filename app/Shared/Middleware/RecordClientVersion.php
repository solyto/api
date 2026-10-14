<?php

namespace App\Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RecordClientVersion
{
    private const string HEADER = 'X-Client-Version';

    private const string PATTERN = '/^\d{1,5}\.\d{1,5}\.\d{1,5}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Runs after $next: the api group runs before the route's auth:sanctum,
        // so the user and token are only resolved once the route has handled it.
        $version = $request->header(self::HEADER);
        if (!is_string($version) || preg_match(self::PATTERN, $version) !== 1) {
            return $response;
        }

        $token = $request->user()?->currentAccessToken();
        if (!$token instanceof PersonalAccessToken || $token->client_version === $version) {
            return $response;
        }

        // A query update, not save(): logout/refresh delete this token during the
        // request, and save() on a deleted model would re-insert it.
        PersonalAccessToken::whereKey($token->getKey())->update(['client_version' => $version]);

        return $response;
    }
}
