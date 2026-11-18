<?php

use App\Api\Users\Models\User;
use App\Mcp\Models\McpToken;
use App\Mcp\Services\McpTokenService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Server\McpServiceProvider as PackageServiceProvider;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;

function mcpToken(User $user): string
{
    return app(McpTokenService::class)->issue($user)['token'];
}

function mcpRequest($test, ?string $token, string $method = 'tools/list')
{
    app('auth')->forgetGuards();

    $headers = ['Accept' => 'application/json, text/event-stream'];
    if ($token !== null) {
        $headers['Authorization'] = 'Bearer ' . $token;
    }

    return $test->withHeaders($headers)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => []]);
}

describe('MCP endpoint access', function () {
    it('lists the tools for an admin with an MCP token', function () {
        enableMcp();

        $response = mcpRequest($this, mcpToken(User::factory()->admin()->create()))->assertOk();

        expect(collect($response->json('result.tools'))->pluck('name')->all())->toEqualCanonicalizing([
            'list-todos', 'search-notes', 'get-note', 'list-upcoming-events', 'search-contacts', 'list-dev-requests', 'get-dev-request',
        ]);
    });

    it('lets a super admin in too', function () {
        enableMcp();

        mcpRequest($this, mcpToken(User::factory()->superAdmin()->create()))->assertOk();
    });

    it('records when a token was last used', function () {
        enableMcp();
        $token = mcpToken(User::factory()->admin()->create());

        mcpRequest($this, $token)->assertOk();

        expect(McpToken::sole()->last_used_at)->not->toBeNull();
    });

    it('does not exist while disabled', function () {
        foreach (['get', 'delete', 'post'] as $method) {
            $this->json($method, '/mcp')->assertNotFound();
        }
    });

    it('does not load the package at all while disabled', function () {
        expect(app()->providerIsLoaded(PackageServiceProvider::class))->toBeFalse()
            ->and(app(Kernel::class)->hasMiddleware(AddWwwAuthenticateHeader::class))->toBeFalse()
            ->and(Artisan::all())->not->toHaveKey('mcp:start');
    });

    it('rejects requests without a token', function () {
        enableMcp();

        mcpRequest($this, null)->assertUnauthorized();
    });

    it('rejects a wrong token', function () {
        enableMcp();

        mcpRequest($this, mcpToken(User::factory()->admin()->create()) . 'x')->assertUnauthorized();
    });

    it('rejects an expired token', function () {
        enableMcp();
        $token = mcpToken(User::factory()->admin()->create());
        $this->travel(config('mcp.token_expiry_days') + 1)->days();

        mcpRequest($this, $token)->assertUnauthorized();
    });

    it('rejects a revoked token immediately', function () {
        enableMcp();
        $admin = User::factory()->admin()->create();
        $token = mcpToken($admin);
        mcpRequest($this, $token)->assertOk();

        app(McpTokenService::class)->revokeAll($admin);

        mcpRequest($this, $token)->assertUnauthorized();
    });

    it('rejects non-admin users', function () {
        enableMcp();

        mcpRequest($this, mcpToken(makeUser()))->assertForbidden();
    });

    it('rejects an admin who was demoted after the token was issued', function () {
        enableMcp();
        $admin = User::factory()->admin()->create();
        $token = mcpToken($admin);
        $admin->update(['role' => 'user']);

        mcpRequest($this, $token)->assertForbidden();
    });

    it('rejects Sanctum tokens, even an admin\'s', function () {
        enableMcp();

        mcpRequest($this, sanctumToken(User::factory()->admin()->create()))->assertUnauthorized();
    });

    it('deletes a user\'s MCP tokens along with the user', function () {
        $admin = User::factory()->admin()->create();
        mcpToken($admin);

        $admin->delete();

        expect(McpToken::count())->toBe(0);
    });
});

describe('MCP token isolation', function () {
    it('does not work on the regular API', function () {
        enableMcp();
        $token = mcpToken(User::factory()->admin()->create());
        app('auth')->forgetGuards();

        $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/v1/users/me')->assertUnauthorized();
    });

    it('does not work on widget routes', function () {
        enableMcp();
        $token = mcpToken(User::factory()->admin()->create());
        app('auth')->forgetGuards();

        $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/v1/widgets/your-day')->assertUnauthorized();
    });

    it('is not a Sanctum token', function () {
        $admin = User::factory()->admin()->create();
        mcpToken($admin);

        expect($admin->tokens()->count())->toBe(0);
    });
});

describe('app:mcp:token', function () {
    it('issues a working token for admins', function () {
        enableMcp();
        $admin = User::factory()->admin()->create();

        Artisan::call('app:mcp:token', ['email' => $admin->email]);
        $lines = array_values(array_filter(array_map('trim', explode("\n", Artisan::output()))));

        expect(McpToken::where('user_id', $admin->id)->count())->toBe(1);
        mcpRequest($this, $lines[1])->assertOk();
    });

    it('stores only the token hash', function () {
        $admin = User::factory()->admin()->create();
        $token = mcpToken($admin);

        expect(McpToken::sole()->token_hash)->not->toBe($token)->toHaveLength(64);
    });

    it('refuses non-admins', function () {
        $user = makeUser();

        $this->artisan('app:mcp:token', ['email' => $user->email])->assertFailed();

        expect(McpToken::count())->toBe(0);
    });

    it('revokes only that user\'s MCP tokens', function () {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();
        mcpToken($admin);
        mcpToken($admin);
        mcpToken($other);

        $this->artisan('app:mcp:token', ['email' => $admin->email, '--revoke' => true])
            ->expectsOutputToContain('Revoked 2 MCP token(s).')
            ->assertSuccessful();

        expect(McpToken::pluck('user_id')->all())->toBe([$other->id]);
    });
});
