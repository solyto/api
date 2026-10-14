<?php

use Illuminate\Support\Facades\Hash;

describe('Client minimum version', function () {
    it('rejects nothing when the minimum is off', function () {
        config(['app.min_client_version' => null]);

        $this->actingAs(makeUser(), 'sanctum')
            ->withHeaders(['X-Client-Version' => '0.0.1'])
            ->getJson('/api/v1/users/me')
            ->assertOk();
    });

    it('returns 426 with the minimum in meta when the header is below it', function () {
        config(['app.min_client_version' => '1.0.10']);

        $response = $this->actingAs(makeUser(), 'sanctum')
            ->withHeaders(['X-Client-Version' => '1.0.9'])
            ->getJson('/api/v1/users/me');

        expectApiError($response, 426);
        $response->assertJsonPath('message', 'Please update the app.')
            ->assertJsonPath('meta.min_client_version', '1.0.10');
    });

    it('accepts a header equal to or above the minimum', function (string $header) {
        config(['app.min_client_version' => '1.0.10']);

        $this->actingAs(makeUser(), 'sanctum')
            ->withHeaders(['X-Client-Version' => $header])
            ->getJson('/api/v1/users/me')
            ->assertOk();
    })->with(['equal' => '1.0.10', 'above' => '1.1.0']);

    it('accepts a request without the header', function () {
        config(['app.min_client_version' => '1.0.16']);

        $this->actingAs(makeUser(), 'sanctum')
            ->getJson('/api/v1/users/me')
            ->assertOk();
    });

    it('accepts an invalid header', function () {
        config(['app.min_client_version' => '1.0.16']);

        $this->actingAs(makeUser(), 'sanctum')
            ->withHeaders(['X-Client-Version' => 'abc'])
            ->getJson('/api/v1/users/me')
            ->assertOk();
    });

    it('keeps health reachable below the minimum', function () {
        config(['app.min_client_version' => '1.0.16']);

        $this->withHeaders(['X-Client-Version' => '1.0.1'])
            ->getJson('/api/v1/health')
            ->assertOk();
    });

    it('keeps login reachable below the minimum', function () {
        config(['app.min_client_version' => '1.0.16']);
        makeUser(['email' => 'old@example.com', 'password' => Hash::make('password1234')]);

        $this->withHeaders(['X-Client-Version' => '1.0.1'])
            ->postJson('/api/v1/auth/login', ['email' => 'old@example.com', 'password' => 'password1234'])
            ->assertOk();
    });
});
