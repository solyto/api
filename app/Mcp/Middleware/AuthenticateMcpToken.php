<?php

namespace App\Mcp\Middleware;

use App\Mcp\Services\McpTokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMcpToken
{
    public function __construct(private readonly McpTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        $token = $bearer ? $this->tokens->find($bearer) : null;

        if (!$token) {
            abort(401);
        }

        abort_unless($token->user->isAdmin(), 403);

        $token->forceFill(['last_used_at' => now()])->save();
        Auth::setUser($token->user);

        return $next($request);
    }
}
