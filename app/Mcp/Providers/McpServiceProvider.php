<?php

namespace App\Mcp\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider as PackageServiceProvider;

/**
 * laravel/mcp is excluded from package discovery (composer.json): its provider adds global middleware,
 * container callbacks, config and commands, so it is only loaded at all while MCP is enabled.
 */
class McpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (config('mcp.enabled')) {
            $this->app->register(PackageServiceProvider::class);
        }
    }
}
