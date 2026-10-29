<?php

use Illuminate\Support\Facades\Route;
use OpenApi\Generator as OpenApiGenerator;

/**
 * Parse the l5-swagger annotations (OpenApiSpec + controllers) and assert
 * that every documented operation/path resolves to a registered route with
 * a matching HTTP verb.
 *
 * OPENAPI_KNOWN_DRIFT must match the actual drift exactly, so any new
 * mismatch between the OpenAPI spec and the route table fails this test,
 * and so does fixing one of the listed entries without removing it.
 */
const OPENAPI_KNOWN_DRIFT = [];

it('documents a route for every OpenAPI path operation', function () {
    $silentLogger = new class implements \Psr\Log\LoggerInterface
    {
        public function emergency($message, array $context = []): void {}

        public function alert($message, array $context = []): void {}

        public function critical($message, array $context = []): void {}

        public function error($message, array $context = []): void {}

        public function warning($message, array $context = []): void {}

        public function notice($message, array $context = []): void {}

        public function info($message, array $context = []): void {}

        public function debug($message, array $context = []): void {}

        public function log($level, $message, array $context = []): void {}
    };

    $openApi = (new OpenApiGenerator($silentLogger))->generate([
        base_path('app/OpenApi'),
        base_path('app/Api'),
        base_path('app/Plugins'),
    ]);

    $routes = Route::getRoutes();

    // Operation paths are relative to the server URL (`/api/v1`), so the
    // Laravel route URI is the server URL plus the operation path.
    expect($openApi->servers[0]->url)->toBe('/api/v1');

    $normalize = fn (string $path): string => 'api/v1/'.ltrim($path, '/');

    $drift = [];

    foreach ($openApi->paths as $path) {
        $pathUri = $normalize($path->path);

        foreach ($path->operations() as $operation) {
            $method = strtolower($operation->method);
            $signature = strtoupper($method).' '.$pathUri;

            $matched = false;
            foreach ($routes->getRoutes() as $route) {
                if ($route->uri === $pathUri && in_array(strtoupper($method), $route->methods(), true)) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $drift[] = $signature;
            }
        }
    }

    sort($drift);
    $known = OPENAPI_KNOWN_DRIFT;
    sort($known);

    expect($drift)->toBe($known, 'OpenAPI drift changed: '.implode(', ', array_diff($drift, $known)));
});
