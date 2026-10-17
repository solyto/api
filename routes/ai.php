<?php

use App\Api\Mcp\Servers\SolytoServer;
use Laravel\Mcp\Facades\Mcp;

// Experimental: off unless MCP_ENABLED=true (config/services.php)
if (config('services.mcp.enabled')) {
    Mcp::web('/mcp', SolytoServer::class)->middleware('auth:sanctum');
}
