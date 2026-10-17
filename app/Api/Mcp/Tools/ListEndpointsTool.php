<?php

namespace App\Api\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use ReflectionMethod;

#[Name('list_endpoints')]
#[Title('List endpoints')]
#[IsReadOnly]
#[Description('List the Solyto REST API endpoints (method, path relative to /api/v1, short description). Pass `filter` to narrow the list by a case-insensitive substring of method, path or description. Call an endpoint with call_api; `{param}` segments are replaced with real ids.')]
class ListEndpointsTool extends Tool
{
    public const string PREFIX = 'api/v1/';

    public function __construct(private readonly Router $router) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['filter' => ['nullable', 'string', 'max:100']]);
        $filter = Str::lower(trim((string) ($validated['filter'] ?? '')));

        $endpoints = [];
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if (!str_starts_with($route->uri(), self::PREFIX)) {
                continue;
            }

            if (CallApiTool::isBlocked($route->uri())) {
                continue;
            }

            $path = Str::after($route->uri(), self::PREFIX);
            $description = $this->describe($route);

            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                if ($filter !== '' && !Str::contains(Str::lower("{$method} {$path} {$description}"), $filter)) {
                    continue;
                }

                $endpoints[] = ['method' => $method, 'path' => $path, 'description' => $description];
            }
        }

        return Response::json(['count' => count($endpoints), 'endpoints' => $endpoints]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'filter' => $schema->string()->description('Optional case-insensitive substring, e.g. "todos" or "calendar".'),
        ];
    }

    // The OpenAPI `summary` of the controller action, so the list follows the annotations without upkeep
    private function describe(Route $route): string
    {
        $controller = $route->getAction('controller');
        if (!is_string($controller) || !str_contains($controller, '@')) {
            return $route->getActionName();
        }

        [$class, $method] = explode('@', $controller, 2);
        if (!method_exists($class, $method)) {
            return class_basename($class)."@{$method}";
        }

        $docComment = (string) (new ReflectionMethod($class, $method))->getDocComment();
        if (preg_match('/@OA\\\\(?:Get|Post|Put|Patch|Delete)\(.*?summary="([^"]*)"/s', $docComment, $matches) === 1) {
            return $matches[1];
        }

        return class_basename($class)."@{$method}";
    }
}
