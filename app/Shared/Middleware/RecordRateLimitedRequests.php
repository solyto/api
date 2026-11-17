<?php

namespace App\Shared\Middleware;

use App\Shared\Pulse\PulseMetrics;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pulse\Facades\Pulse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts 429 responses for the Pulse rate limits card, keyed by limiter and
 * route. Registered as global middleware (bootstrap/app.php), so it runs
 * outside the routes' throttle middleware and sees the 429 the router renders
 * from ThrottleRequestsException (which Laravel never reports, so Pulse's
 * exception card doesn't show it either).
 */
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

        return ($limiters !== '' ? $limiters : 'throttle').' · '.$request->method().' /'.ltrim($route->uri(), '/');
    }
}
