<?php

namespace App\Shared\Middleware;

use App\Api\ApiResponse;
use App\Shared\Helpers\ClientVersionHelper;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RecordClientVersion
{
    private const int LAST_USED_THRESHOLD_MINUTES = 5;

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isBelowMinimum($request)) {
            return ApiResponse::error('Please update the app.', 426, meta: ['min_client_version' => config('app.min_client_version')]);
        }

        $response = $next($request);

        // Runs after $next: the api group runs before the route's auth:sanctum,
        // so the user and token are only resolved once the route has handled it.
        // Unsaved tokens: Sanctum::actingAs() mocks one, and logout/refresh delete it.
        $token = $request->user()?->currentAccessToken();
        if (!$token instanceof PersonalAccessToken || !$token->exists) {
            return $response;
        }

        $changes = [];

        // Replaces Sanctum's per-request write (disabled in config/sanctum.php).
        $staleBefore = now()->subMinutes(self::LAST_USED_THRESHOLD_MINUTES);
        if ($token->last_used_at === null || $token->last_used_at->lt($staleBefore)) {
            $changes['last_used_at'] = now();
        }

        $version = ClientVersionHelper::fromRequest($request);
        if ($version !== null && $token->client_version !== $version) {
            $changes['client_version'] = $version;
        }

        if ($changes === []) {
            return $response;
        }

        // A query update, not save(): logout/refresh delete this token during the
        // request, and save() on a deleted model would re-insert it.
        PersonalAccessToken::whereKey($token->getKey())->update($changes);

        return $response;
    }

    private function isBelowMinimum(Request $request): bool
    {
        $min = config('app.min_client_version');
        if (!is_string($min) || $min === '') {
            return false;
        }

        // A missing or invalid header is allowed: builds up to 1.0.15 send none.
        $version = ClientVersionHelper::fromRequest($request);
        if ($version === null) {
            return false;
        }

        // Only the auth:sanctum routes: health and login stay reachable for outdated builds.
        if (!in_array('auth:sanctum', $request->route()?->gatherMiddleware() ?? [], true)) {
            return false;
        }

        return version_compare($version, $min, '<');
    }
}
