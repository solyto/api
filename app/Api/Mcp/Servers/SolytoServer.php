<?php

namespace App\Api\Mcp\Servers;

use App\Api\Mcp\Tools\CallApiTool;
use App\Api\Mcp\Tools\ListEndpointsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Solyto')]
#[Version('0.1.0')]
#[Instructions('Gives access to the Solyto REST API (todos, calendar, contacts, notes, feeds, libraries, time tracking, finances and more) as the authenticated user. Use list_endpoints to find an endpoint, then call_api to call it. Responses are the API\'s JSON envelope; on a 422 read `errors` and retry with corrected input.')]
class SolytoServer extends Server
{
    protected array $tools = [
        ListEndpointsTool::class,
        CallApiTool::class,
    ];
}
