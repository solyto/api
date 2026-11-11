<?php

use App\Api\Users\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

function lastUsedToken(): array
{
    $user = User::factory()->create();
    $newToken = $user->createToken('auth-token-web');

    return [$newToken->plainTextToken, $newToken->accessToken];
}

function lastUsedAt(PersonalAccessToken $token): ?string
{
    return PersonalAccessToken::find($token->id)->last_used_at?->toDateTimeString();
}

describe('token last_used_at', function () {
    beforeEach(function () {
        $this->travelTo(now()->startOfSecond());
    });

    it('sets a missing last_used_at on the first request', function () {
        [$plain, $token] = lastUsedToken();

        $this->withHeaders(['Authorization' => "Bearer {$plain}"])
            ->getJson('/api/v1/users/me')
            ->assertOk();

        expect(lastUsedAt($token))->toBe(now()->toDateTimeString());
    });

    it('does not write again within five minutes', function () {
        [$plain, $token] = lastUsedToken();
        $firstUse = now()->toDateTimeString();

        $this->withHeaders(['Authorization' => "Bearer {$plain}"])
            ->getJson('/api/v1/users/me')
            ->assertOk();

        $this->travel(4)->minutes();
        $this->app['auth']->forgetGuards();

        $this->withHeaders(['Authorization' => "Bearer {$plain}"])
            ->getJson('/api/v1/users/me')
            ->assertOk();

        expect(lastUsedAt($token))->toBe($firstUse);
    });

    it('updates last_used_at after more than five minutes', function () {
        [$plain, $token] = lastUsedToken();

        $this->withHeaders(['Authorization' => "Bearer {$plain}"])
            ->getJson('/api/v1/users/me')
            ->assertOk();

        $this->travel(6)->minutes();
        $this->app['auth']->forgetGuards();

        $this->withHeaders(['Authorization' => "Bearer {$plain}"])
            ->getJson('/api/v1/users/me')
            ->assertOk();

        expect(lastUsedAt($token))->toBe(now()->toDateTimeString());
    });

    it('updates a stale last_used_at without an X-Client-Version header', function () {
        [$plain, $token] = lastUsedToken();
        $token->forceFill(['last_used_at' => now()->subHour(), 'client_version' => '1.0.16'])->save();

        $this->withHeaders(['Authorization' => "Bearer {$plain}"])
            ->getJson('/api/v1/users/me')
            ->assertOk();

        expect(lastUsedAt($token))->toBe(now()->toDateTimeString())
            ->and(PersonalAccessToken::find($token->id)->client_version)->toBe('1.0.16');
    });

    it('writes a version change and a stale timestamp in one update', function () {
        [$plain, $token] = lastUsedToken();
        $token->forceFill(['last_used_at' => now()->subHour(), 'client_version' => '1.0.16'])->save();

        $updates = 0;
        DB::listen(function (QueryExecuted $query) use (&$updates) {
            if (str_starts_with(strtolower($query->sql), 'update "personal_access_tokens"')) {
                $updates++;
            }
        });

        $this->withHeaders(['Authorization' => "Bearer {$plain}", 'X-Client-Version' => '1.1.0'])
            ->getJson('/api/v1/users/me')
            ->assertOk();

        $stored = PersonalAccessToken::find($token->id);
        expect($updates)->toBe(1)
            ->and($stored->client_version)->toBe('1.1.0')
            ->and($stored->last_used_at->toDateTimeString())->toBe(now()->toDateTimeString());
    });

    it('does not recreate a token with a stale timestamp', function (string $endpoint) {
        [$plain, $token] = lastUsedToken();
        $token->forceFill(['last_used_at' => now()->subHour()])->save();

        $this->withHeaders(['Authorization' => "Bearer {$plain}"])
            ->postJson($endpoint)
            ->assertOk();

        expect(PersonalAccessToken::find($token->id))->toBeNull();
    })->with(['/api/v1/auth/logout', '/api/v1/auth/refresh']);
});
