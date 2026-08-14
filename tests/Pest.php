<?php

use App\Api\Users\Models\User;
use Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit', '../app/Api', '../app/Bots/Tests', '../app/Dav/Tests');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/
expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Additional Expectations
|--------------------------------------------------------------------------
|
| The pre-existing suite relies on a few convenience expectations that are
| not part of Pest 4 out of the box. They are registered here so that both
| the existing factory/model tests and the newly added tests can use them.
|
*/

expect()->extend('toBeBoolean', function () {
    return $this->toBeBool();
});

expect()->extend('toBeEmail', function () {
    return $this->toBeString()
        ->when(filter_var($this->value, FILTER_VALIDATE_EMAIL) === false, function ($expectation) {
            throw new \Exception(sprintf(
                'Failed asserting that [%s] is a valid email address.',
                is_scalar($this->value) ? (string) $this->value : gettype($this->value),
            ));
        });
});

expect()->extend('greaterThan', function ($expected) {
    return $this->toBeGreaterThan($expected);
});

expect()->extend('lessThan', function ($expected) {
    return $this->toBeLessThan($expected);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

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
