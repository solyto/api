<?php

namespace App\Shared\Middleware;

use App\Shared\Notifications\ApiTrafficAlertNotification;
use App\Shared\Notifications\Channels\NtfyChannel;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

// Observe only: counts requests per user and alerts the admins, never blocks.
class TrackApiTraffic
{
    private const int WINDOW_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->user()?->getAuthIdentifier();
        if ($userId === null) {
            return $next($request);
        }

        $count = RateLimiter::hit("api-traffic:{$userId}", self::WINDOW_SECONDS);

        if (
            $count > (int) config('services.traffic.alert_threshold')
            && Cache::add("api-traffic-alerted:{$userId}", true, (int) config('services.traffic.alert_cooldown'))
        ) {
            $route = $request->route();

            Log::channel('traffic')->warning('API traffic threshold exceeded', [
                'user_id' => $userId,
                'count'   => $count,
                'route'   => $route?->getName() ?? $route?->uri(),
            ]);

            Notification::route(NtfyChannel::class, config('services.ntfy.topics.traffic') ?: config('services.ntfy.topic'))
                ->notify(new ApiTrafficAlertNotification((string) $userId, $count));
        }

        return $next($request);
    }
}
