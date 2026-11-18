<?php

use App\Mcp\Middleware\AuthenticateMcpToken;
use App\Mcp\SolytoServer;
use App\Shared\Middleware\TrackApiTraffic;
use Laravel\Mcp\Facades\Mcp;

if (!config('mcp.enabled')) {
    return;
}

Mcp::web('/mcp', SolytoServer::class)
    ->middleware([AuthenticateMcpToken::class, 'throttle:60,1', TrackApiTraffic::class]);
