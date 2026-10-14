<?php

use App\Api\Users\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

function clientVersionToken(): array
{
    $user = User::factory()->create();
    $newToken = $user->createToken('auth-token-web');

    return [$newToken->plainTextToken, $newToken->accessToken];
}

describe('X-Client-Version header', function () {
    it('records a valid version on the request token', function () {
        [$plain, $token] = clientVersionToken();

        $this->withHeaders(['Authorization' => "Bearer {$plain}", 'X-Client-Version' => '1.0.16'])
            ->getJson('/api/v1/users/me')
            ->assertOk();

        expect(PersonalAccessToken::find($token->id)->client_version)->toBe('1.0.16');
    });

    it('updates the version when a newer build sends a different one', function () {
        [$plain, $token] = clientVersionToken();

        $this->withHeaders(['Authorization' => "Bearer {$plain}", 'X-Client-Version' => '1.0.16'])
            ->getJson('/api/v1/users/me')
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withHeaders(['Authorization' => "Bearer {$plain}", 'X-Client-Version' => '1.1.0'])
            ->getJson('/api/v1/users/me')
            ->assertOk();

        expect(PersonalAccessToken::find($token->id)->client_version)->toBe('1.1.0');
    });

    it('leaves the version untouched when the header is missing', function () {
        [$plain, $token] = clientVersionToken();
        $token->forceFill(['client_version' => '1.0.16'])->save();

        $this->withHeaders(['Authorization' => "Bearer {$plain}"])
            ->getJson('/api/v1/users/me')
            ->assertOk();

        expect(PersonalAccessToken::find($token->id)->client_version)->toBe('1.0.16');
    });

    it('ignores an invalid header without rejecting the request', function (string $header) {
        [$plain, $token] = clientVersionToken();
        $token->forceFill(['client_version' => '1.0.16'])->save();

        $this->withHeaders(['Authorization' => "Bearer {$plain}", 'X-Client-Version' => $header])
            ->getJson('/api/v1/users/me')
            ->assertOk();

        expect(PersonalAccessToken::find($token->id)->client_version)->toBe('1.0.16');
    })->with(['abc', '1.0', '1.0.16-beta', 'v1.0.16', '1.0.16 ', '123456.0.0', str_repeat('1', 40)]);

    it('does not recreate the token on logout', function () {
        [$plain, $token] = clientVersionToken();

        $this->withHeaders(['Authorization' => "Bearer {$plain}", 'X-Client-Version' => '1.0.16'])
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        expect(PersonalAccessToken::find($token->id))->toBeNull();

        $this->app['auth']->forgetGuards();

        $this->withHeaders(['Authorization' => "Bearer {$plain}"])
            ->getJson('/api/v1/users/me')
            ->assertUnauthorized();
    });

    it('does not recreate the old token on refresh', function () {
        [$plain, $token] = clientVersionToken();

        $this->withHeaders(['Authorization' => "Bearer {$plain}", 'X-Client-Version' => '1.0.16'])
            ->postJson('/api/v1/auth/refresh')
            ->assertOk();

        expect(PersonalAccessToken::find($token->id))->toBeNull();

        $this->app['auth']->forgetGuards();

        $this->withHeaders(['Authorization' => "Bearer {$plain}"])
            ->getJson('/api/v1/users/me')
            ->assertUnauthorized();
    });

    it('accepts the header on a public route', function () {
        $this->withHeaders(['X-Client-Version' => '1.0.16'])
            ->getJson('/api/v1/health')
            ->assertOk();

        expect(PersonalAccessToken::whereNotNull('client_version')->exists())->toBeFalse();
    });

    it('allows the header in a CORS preflight', function () {
        $response = $this->call('OPTIONS', '/api/v1/users/me', server: [
            'HTTP_ORIGIN' => 'https://app.solyto.app',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'X-Client-Version',
        ]);

        expect($response->getStatusCode())->toBeLessThan(300);
        expect(strtolower((string) $response->headers->get('Access-Control-Allow-Headers')))
            ->toContain('x-client-version');
    });
});
