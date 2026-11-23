<?php

namespace App\Shared\Middleware;

use App\Shared\Pulse\PulseMetrics;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pulse\Facades\Pulse;
use Symfony\Component\HttpFoundation\Response;

// Global middleware (bootstrap/app.php), so it sees the 429 the router renders from ThrottleRequestsException.
// Laravel never reports that exception, so Pulse's exception card doesn't show rate limiting either.
class RecordRateLimitedRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() === Response::HTTP_TOO_MANY_REQUESTS) {
            // Inside rescue(): building the key must not break the response either.
            Pulse::rescue(fn () => PulseMetrics::rateLimited($this->key($request)));
        }

        return $response;
    }

    private function key(Request $request): string
    {
        $route = $request->route();

        if ($route === null) {
            return $request->method().' '.$request->path();
        }

        $limiters = collect($route->gatherMiddleware())
            ->filter(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'))
            ->map(fn (string $middleware) => explode(',', substr($middleware, strlen('throttle:')))[0])
            ->implode(', ');

        $limiterLabel = $limiters !== '' ? $limiters : 'throttle';

        return $limiterLabel.' · '.$request->method().' /'.ltrim($route->uri(), '/');
    }
}
