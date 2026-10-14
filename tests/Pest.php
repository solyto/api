<?php

use App\Api\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit', '../app/Api', '../app/Bots/Tests', '../app/Dav/Tests', '../app/Plugins');
uses(RefreshDatabase::class)->in('../app/Api', '../app/Plugins');

/*
|--------------------------------------------------------------------------
| Additional Expectations
|--------------------------------------------------------------------------
|
| Convenience expectations that are not part of Pest 4 out of the box.
|
*/

expect()->extend('toBeEmail', function () {
    return $this->toBeString()
        ->when(filter_var($this->value, FILTER_VALIDATE_EMAIL) === false, function ($expectation) {
            throw new \Exception(sprintf(
                'Failed asserting that [%s] is a valid email address.',
                is_scalar($this->value) ? (string) $this->value : gettype($this->value),
            ));
        });
});

/*
|--------------------------------------------------------------------------
| Shared helpers for authenticated API requests
|--------------------------------------------------------------------------
|
| These helpers are used across all feature tests (including the tests in
| the app/Api modules) to create a user and attach a Sanctum bearer token
| to API requests.
|
*/

/**
 * Create a user for API tests (verified by default so protected routes
 * that require a verified email can be exercised directly).
 */
function makeUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'email_verified_at' => now(),
    ], $attributes));
}

/**
 * Issue a fresh Sanctum personal access token for the given user and
 * return the plain-text token used in the Authorization header.
 */
function sanctumToken(User $user): string
{
    return $user->createToken('api-test')->plainTextToken;
}

/**
 * Build the Authorization headers for an authenticated API request.
 */
function authHeaders(User $user): array
{
    return ['Authorization' => 'Bearer '.sanctumToken($user)];
}

/**
 * Assert the given response is an `ApiResponse` success envelope.
 */
function expectApiSuccess($response, int $status = 200): void
{
    $response->assertStatus($status);
    $response->assertJsonPath('success', true);
}

/**
 * Assert the given response is an `ApiResponse` error envelope.
 */
function expectApiError($response, int $status): void
{
    $response->assertStatus($status);
    $response->assertJsonPath('success', false);
}
