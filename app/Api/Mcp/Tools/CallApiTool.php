<?php

namespace App\Api\Mcp\Tools;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

#[Name('call_api')]
#[Title('Call API')]
#[Description('Call a Solyto REST API endpoint as the authenticated user and get its JSON response back, including 4xx error and 422 validation responses. `path` is relative to /api/v1 (e.g. "todos" or "todos/12"); find endpoints with list_endpoints. Put query-string parameters in `query` and the JSON request body in `body`. Endpoints that would revoke the MCP token or sign the user out (auth/*, users/change-password) are refused.')]
class CallApiTool extends Tool
{
    public const array METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    // Route URIs the agent may not call: they log in/out, revoke or rotate tokens (auth/refresh deletes the current one), or revoke every token (change-password)
    private const array BLOCKED_URIS = [
        'api/v1/auth/register',
        'api/v1/auth/login',
        'api/v1/auth/verify',
        'api/v1/auth/forgot-password',
        'api/v1/auth/reset-password',
        'api/v1/auth/passkey/authenticate-options',
        'api/v1/auth/passkey/authenticate',
        'api/v1/auth/logout',
        'api/v1/auth/logout-all',
        'api/v1/auth/refresh',
        'api/v1/auth/revoke-token',
        'api/v1/users/change-password',
    ];

    public function __construct(
        private readonly Kernel $kernel,
        private readonly Router $router,
    ) {}

    public static function isBlocked(string $routeUri): bool
    {
        return in_array($routeUri, self::BLOCKED_URIS, true);
    }

    public function handle(Request $request): Response
    {
        $request->merge(['method' => strtoupper((string) $request->get('method'))]);
        $validated = $request->validate([
            'method' => ['required', 'string', 'in:'.implode(',', self::METHODS)],
            'path' => ['required', 'string', 'max:500'],
            'query' => ['nullable', 'array'],
            'body' => ['nullable', 'array'],
        ]);

        $path = $this->normalisePath($validated['path']);
        if ($path === null) {
            return Response::error('Invalid path: use a path relative to /api/v1 such as "todos" or "todos/12", without scheme, host, "..", query string or fragment.');
        }

        $outer = request();
        $authorization = $outer->header('Authorization');
        if (!is_string($authorization) || $authorization === '') {
            return Response::error('call_api needs the bearer token of the MCP connection.');
        }

        $uri = '/'.ListEndpointsTool::PREFIX.$path;
        $query = $validated['query'] ?? [];
        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        $body = $validated['body'] ?? null;
        $subRequest = HttpRequest::create(
            $uri,
            $validated['method'],
            server: [
                'HTTP_AUTHORIZATION' => $authorization,
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'REMOTE_ADDR' => $outer->ip(),
                'HTTP_USER_AGENT' => 'solyto-mcp',
            ],
            content: $body === null ? null : (string) json_encode($body),
        );

        $routeUri = $this->matchedRouteUri($subRequest);
        if ($routeUri !== null && !str_starts_with($routeUri, ListEndpointsTool::PREFIX)) {
            return Response::error('Invalid path: only /api/v1 endpoints can be called.');
        }
        if ($routeUri !== null && self::isBlocked($routeUri)) {
            return Response::error("{$subRequest->getMethod()} {$path} is not available through MCP because it would sign out the user or revoke the MCP token.");
        }

        $response = $this->dispatch($subRequest, $outer);

        return $this->toToolResponse($response);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'method' => $schema->string()->enum(self::METHODS)->description('HTTP method.')->required(),
            'path' => $schema->string()->description('Path relative to /api/v1, e.g. "todos" or "notes/5". A leading "/api/v1/" is accepted.')->required(),
            'query' => $schema->object()->description('Optional query-string parameters, e.g. {"page": 2}.'),
            'body' => $schema->object()->description('Optional JSON request body for POST/PUT/PATCH.'),
        ];
    }

    // Returns the path below /api/v1 or null when it could escape that prefix
    private function normalisePath(string $path): ?string
    {
        $path = trim($path);
        if (preg_match('/[\x00-\x20\x7f\\\\?#%]/', $path) === 1 || str_contains($path, '://') || str_starts_with($path, '//')) {
            return null;
        }

        $path = ltrim($path, '/');
        if (str_starts_with($path, ListEndpointsTool::PREFIX)) {
            $path = substr($path, strlen(ListEndpointsTool::PREFIX));
        }

        $segments = explode('/', rtrim($path, '/'));
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return implode('/', $segments);
    }

    private function matchedRouteUri(HttpRequest $request): ?string
    {
        try {
            return $this->router->getRoutes()->match($request)->uri();
        } catch (HttpException) {
            // Unknown path or method: the kernel renders the API's own 404/405 envelope
            return null;
        }
    }

    // Runs the request through the full HTTP stack (middleware, auth:sanctum, FormRequests, ownership checks), then restores the MCP request
    private function dispatch(HttpRequest $subRequest, HttpRequest $outer): SymfonyResponse
    {
        Auth::forgetGuards();

        try {
            return $this->kernel->handle($subRequest);
        } finally {
            app()->instance('request', $outer);
            Facade::clearResolvedInstance('request');
            Auth::forgetGuards();
        }
    }

    private function toToolResponse(SymfonyResponse $response): Response
    {
        $status = $response->getStatusCode();
        $content = $response->getContent();
        $contentType = (string) $response->headers->get('Content-Type');

        $decoded = is_string($content) && str_contains($contentType, 'json') ? json_decode($content, true) : null;
        $payload = ['status' => $status];
        if ($decoded !== null) {
            $payload['body'] = $decoded;
        } else {
            $size = is_string($content) ? strlen($content) : 0;
            $payload['body'] = "Non-JSON response ({$contentType}, {$size} bytes) omitted.";
        }

        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $status >= 400 ? Response::error($json) : Response::text($json);
    }
}
