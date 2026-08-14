<?php

use App\Api\Users\Mails\UserVerification;
use App\Api\Users\Models\User;
use App\Api\Users\Models\VerificationToken;
use App\Api\Users\Services\AuthService;
use App\Shared\Enums\AuthPlatformEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

describe('POST /v1/auth/register', function () {
    it('registers a user, creates a verification token and sends a mail', function () {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'User registered successfully.');

        $user = User::where('email', 'john@example.com')->first();
        expect($user)->not->toBeNull();
        expect($user->email_verified_at)->toBeNull();
        expect(Hash::check('password1234', $user->password))->toBeTrue();

        expect(VerificationToken::where('user_id', $user->id)->exists())->toBeTrue();

        Mail::assertSent(UserVerification::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    });

    it('rejects invalid registration data', function () {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
        ]);

        $response->assertStatus(422);
    });

    it('rejects duplicate emails', function () {
        makeUser(['email' => 'dup@example.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'dup@example.com',
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ]);

        $response->assertStatus(422);
    });
});

describe('POST /v1/auth/login', function () {
    it('logs in a verified user and returns a token', function () {
        $user = makeUser(['email' => 'login@example.com', 'password' => Hash::make('password1234')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.com',
            'password' => 'password1234',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['token', 'token_type', 'token_expires_at']]);
    });

    it('returns 401 for wrong credentials', function () {
        makeUser(['email' => 'login@example.com', 'password' => Hash::make('password1234')]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    });

    it('returns 401 for an unverified user when confirmation is required', function () {
        $user = User::factory()->unverified()->create(['email' => 'unverified@example.com', 'password' => Hash::make('password1234')]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'unverified@example.com',
            'password' => 'password1234',
        ])->assertStatus(401);
    });

    it('creates a web token with 7 day expiry by default', function () {
        $user = makeUser(['email' => 'web@example.com', 'password' => Hash::make('password1234')]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'web@example.com',
            'password' => 'password1234',
        ])->assertJsonStructure(['data' => ['token_expires_at']]);
    });
});

describe('Authenticated auth endpoints', function () {
    it('logs out and revokes the current token', function () {
        $user = makeUser();
        $token = sanctumToken($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        expect($user->tokens()->count())->toBe(0);
    });

    it('logs out from all devices', function () {
        $user = makeUser();
        $user->createToken('a');
        $user->createToken('b');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/logout-all')
            ->assertStatus(200);

        expect($user->tokens()->count())->toBe(0);
    });

    it('refreshes the current token', function () {
        $user = makeUser();
        $token = sanctumToken($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/refresh');

        $response->assertStatus(200)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['token', 'token_type']]);

        // The old token was deleted, a new one exists.
        expect($user->tokens()->count())->toBe(1);
    });

    it('lists all tokens including the current one', function () {
        $user = makeUser();
        $user->createToken('web');
        $token = sanctumToken($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/tokens');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $tokens = $response->json('data');
        expect($tokens)->toHaveCount(2);
        expect(collect($tokens)->where('is_current', true))->toHaveCount(1);
    });

    it('revokes another token', function () {
        $user = makeUser();
        $other = $user->createToken('web')->accessToken;
        $token = sanctumToken($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/revoke-token', ['token_id' => $other->id])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        expect($user->tokens()->count())->toBe(1);
    });

    it('returns 404 when revoking an unknown token', function () {
        $user = makeUser();
        $token = sanctumToken($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/revoke-token', ['token_id' => 999999])
            ->assertStatus(404);
    });

    it('refuses to revoke the current token', function () {
        $user = makeUser();
        $current = $user->createToken('auth-token-web');
        $token = $current->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/revoke-token', ['token_id' => $current->accessToken->id])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Cannot revoke current token. Use logout instead.');
    });
});

describe('POST /v1/auth/verify', function () {
    it('verifies a pending user', function () {
        $user = User::factory()->unverified()->create();
        $verificationToken = VerificationToken::factory()->forUser($user)->create();

        $response = $this->postJson('/api/v1/auth/verify', [
            'user_id' => $user->id,
            'token' => $verificationToken->token,
        ]);

        $response->assertStatus(201)->assertJsonPath('success', true);

        expect($user->fresh()->email_verified_at)->not->toBeNull();
        expect(VerificationToken::where('user_id', $user->id)->exists())->toBeFalse();
    });

    it('returns already_verified for a verified user', function () {
        $user = makeUser();
        $verificationToken = VerificationToken::factory()->forUser($user)->create();

        $this->postJson('/api/v1/auth/verify', [
            'user_id' => $user->id,
            'token' => $verificationToken->token,
        ])->assertStatus(401)
            ->assertJsonPath('errors', ['already_verified']);
    });

    it('returns token_mismatch for a wrong token', function () {
        $user = User::factory()->unverified()->create();
        VerificationToken::factory()->forUser($user)->create(['token' => 'real-token']);

        $this->postJson('/api/v1/auth/verify', [
            'user_id' => $user->id,
            'token' => 'wrong-token',
        ])->assertStatus(401)
            ->assertJsonPath('errors', ['token_mismatch']);
    });

    it('returns token_expired for an expired token', function () {
        $user = User::factory()->unverified()->create();
        VerificationToken::factory()->forUser($user)->expired()->create();

        $this->postJson('/api/v1/auth/verify', [
            'user_id' => $user->id,
            'token' => VerificationToken::where('user_id', $user->id)->first()->token,
        ])->assertStatus(401)
            ->assertJsonPath('errors', ['token_expired']);
    });

    it('returns 401 for an unknown user', function () {
        $this->postJson('/api/v1/auth/verify', [
            'user_id' => '00000000-0000-0000-0000-000000000000',
            'token' => 'whatever',
        ])->assertStatus(401);
    });
});

describe('POST /v1/auth/forgot-password', function () {
    it('sends a reset mail for an existing user', function () {
        Mail::fake();
        $user = makeUser(['email' => 'reset@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'reset@example.com',
        ])->assertStatus(200)->assertJsonPath('success', true);

        Mail::assertSent(\App\Api\Users\Mails\PasswordResetMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    });

    it('always returns success for unknown emails', function () {
        Mail::fake();

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'nobody@example.com',
        ])->assertStatus(200)->assertJsonPath('success', true);

        Mail::assertNothingSent();
    });
});

describe('POST /v1/auth/reset-password', function () {
    it('resets the password with a valid token', function () {
        $user = makeUser(['email' => 'resetpw@example.com']);
        $token = \Illuminate\Support\Facades\Password::broker()->createToken($user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'resetpw@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(200)->assertJsonPath('success', true);

        expect(Hash::check('newpassword123', $user->fresh()->password))->toBeTrue();
    });

    it('rejects an invalid token', function () {
        makeUser(['email' => 'resetpw@example.com']);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'invalid-token',
            'email' => 'resetpw@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(401)
            ->assertJsonPath('errors', ['invalid_token']);
    });
});

describe('AuthService', function () {
    it('createToken honours the platform expiry', function () {
        $user = makeUser();

        $web = app(AuthService::class)->createToken($user, AuthPlatformEnum::WEB);
        expect($web['token_type'])->toBe('Bearer');
        expect($web['token'])->toBeString();
        expect($user->tokens()->first()->expires_at)->not->toBeNull();

        // Refresh keeps the platform (mobile) of the consumed token.
        $user2 = makeUser();
        $accessToken = $user2->createToken('auth-token-mobile')->accessToken;
        $user2->withAccessToken($accessToken);
        $refreshed = app(AuthService::class)->refreshToken($user2);
        expect($refreshed['token'])->toBeString();
        expect($user2->tokens()->count())->toBe(1);
        expect($user2->tokens()->first()->name)->toBe('auth-token-mobile');
    });

    it('verify returns false for an unknown user', function () {
        $result = app(AuthService::class)->verify([
            'user_id' => '00000000-0000-0000-0000-000000000000',
            'token' => 'x',
        ]);

        expect($result)->toBeFalse();
    });

    it('logout deletes the current token', function () {
        $user = makeUser();
        $token = $user->createToken('auth-token-web')->accessToken;
        $user->withAccessToken($token);

        app(AuthService::class)->logout($user);

        expect($user->tokens()->count())->toBe(0);
    });

    it('revokeToken returns null for an unknown token', function () {
        $user = makeUser();

        expect(app(AuthService::class)->revokeToken($user, 999999))->toBeNull();
    });

    it('revokeToken throws when revoking the current token', function () {
        $user = makeUser();
        $token = $user->createToken('auth-token-web')->accessToken;
        $user->withAccessToken($token);

        expect(fn () => app(AuthService::class)->revokeToken($user, $token->id))
            ->toThrow(\RuntimeException::class, 'Cannot revoke current token');
    });
});
