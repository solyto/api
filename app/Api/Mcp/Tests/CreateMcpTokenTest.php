<?php

use App\Api\Mcp\Commands\CreateMcpToken;

describe('app:mcp:token', function () {
    it('creates a non-expiring MCP token and prints it once', function () {
        $user = makeUser();

        $this->artisan('app:mcp:token', ['email' => $user->email])
            ->expectsOutputToContain('shown only once')
            ->assertSuccessful();

        $token = $user->tokens()->sole();
        expect($token->name)->toBe(CreateMcpToken::TOKEN_NAME)
            ->and($token->expires_at)->toBeNull();
    });

    it('prints a token that authenticates the MCP server', function () {
        mcpEnable();
        $user = makeUser();

        \Illuminate\Support\Facades\Artisan::call('app:mcp:token', ['email' => $user->email]);
        $plain = trim(collect(explode("\n", trim(\Illuminate\Support\Facades\Artisan::output())))->last());

        $me = mcpTool($plain, 'call_api', ['method' => 'GET', 'path' => 'users/me']);

        expect($me['json']['body']['data']['email'])->toBe($user->email);
    });

    it('fails for an unknown email', function () {
        $this->artisan('app:mcp:token', ['email' => 'nobody@example.com'])
            ->expectsOutputToContain('No user found')
            ->assertFailed();
    });
});
