<?php

use App\Api\Todos\Models\Todo;
use App\Api\Users\Mails\AccountDeletionMail;
use App\Api\Users\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

function requestDeletionToken(User $user): string
{
    Mail::fake();

    test()->actingAs($user, 'sanctum')
        ->postJson('/api/v1/users/me/deletion-request', [
            'password' => 'secret-password',
            'confirmation' => 'DELETE',
        ])
        ->assertStatus(200);

    $token = null;
    Mail::assertSent(AccountDeletionMail::class, function (AccountDeletionMail $mail) use (&$token) {
        $token = $mail->content()->with['token'];

        return true;
    });

    return $token;
}

describe('POST /api/v1/users/me/deletion-request', function () {
    it('mails a confirmation link and deletes nothing yet', function () {
        Mail::fake();
        $user = makeUser(['password' => Hash::make('secret-password')]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/users/me/deletion-request', [
                'password' => 'secret-password',
                'confirmation' => 'DELETE',
                'platform' => 'mobile',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'email_sent');

        Mail::assertSent(AccountDeletionMail::class, function (AccountDeletionMail $mail) use ($user) {
            $html = $mail->render();

            return $mail->hasTo($user->email)
                && str_contains($html, '/auth/confirm-account-deletion?token=')
                && str_contains($html, '&amp;platform=mobile');
        });
        expect(User::find($user->id))->not->toBeNull();
    });

    it('rejects a wrong password', function () {
        Mail::fake();
        $user = makeUser(['password' => Hash::make('secret-password')]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/users/me/deletion-request', [
                'password' => 'wrong-password',
                'confirmation' => 'DELETE',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        Mail::assertNothingSent();
    });

    it('rejects a missing or wrong confirmation word', function (?string $confirmation) {
        Mail::fake();
        $user = makeUser(['password' => Hash::make('secret-password')]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/users/me/deletion-request', array_filter([
                'password' => 'secret-password',
                'confirmation' => $confirmation,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirmation');

        Mail::assertNothingSent();
    })->with([null, 'delete', 'yes']);

    it('forbids super administrators', function () {
        Mail::fake();
        $user = makeUser(['password' => Hash::make('secret-password'), 'role' => 'super_admin']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/users/me/deletion-request', [
                'password' => 'secret-password',
                'confirmation' => 'DELETE',
            ])
            ->assertStatus(403);

        Mail::assertNothingSent();
    });

    it('deletes right away when deletion confirmation is off', function () {
        Mail::fake();
        config(['auth.policy.account_deletion_confirmation' => false]);
        $user = makeUser(['password' => Hash::make('secret-password')]);
        $todo = Todo::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/users/me/deletion-request', [
                'password' => 'secret-password',
                'confirmation' => 'DELETE',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'deleted');

        Mail::assertNothingSent();
        expect(User::find($user->id))->toBeNull()
            ->and(Todo::find($todo->id))->toBeNull();
    });

    it('deletes right away and warns when mailgun is not configured', function () {
        Mail::fake();
        $warnings = [];
        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$warnings) {
            if ($log->level === 'warning') {
                $warnings[] = $log->message;
            }
        });
        config(['mail.default' => 'mailgun', 'services.mailgun.domain' => null, 'services.mailgun.secret' => null]);
        $user = makeUser(['password' => Hash::make('secret-password')]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/users/me/deletion-request', [
                'password' => 'secret-password',
                'confirmation' => 'DELETE',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'deleted');

        Mail::assertNothingSent();
        expect(User::find($user->id))->toBeNull()
            ->and($warnings)->toContain('Account deletion confirmation is enabled but mail is not configured; deleting without an email link.');
    });

    it('still validates password and confirmation without email confirmation', function () {
        config(['auth.policy.account_deletion_confirmation' => false]);
        $user = makeUser(['password' => Hash::make('secret-password')]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/users/me/deletion-request', [
                'password' => 'wrong-password',
                'confirmation' => 'delete',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password', 'confirmation']);

        expect(User::find($user->id))->not->toBeNull();
    });

    it('requires authentication', function () {
        $this->postJson('/api/v1/users/me/deletion-request', [
            'password' => 'secret-password',
            'confirmation' => 'DELETE',
        ])->assertStatus(401);
    });

    it('is throttled', function () {
        Mail::fake();
        $user = makeUser(['password' => Hash::make('secret-password')]);
        RateLimiter::clear('account-deletion');

        foreach (range(1, 5) as $ignored) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/v1/users/me/deletion-request', ['password' => 'secret-password', 'confirmation' => 'DELETE'])
                ->assertStatus(200);
        }

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/users/me/deletion-request', ['password' => 'secret-password', 'confirmation' => 'DELETE'])
            ->assertStatus(429)
            ->assertJsonPath('success', false);
    });
});

describe('POST /api/v1/auth/confirm-account-deletion', function () {
    it('deletes the account and its data with a valid token', function () {
        $user = makeUser(['password' => Hash::make('secret-password')]);
        $other = makeUser();
        $todo = Todo::factory()->forUser($user)->create();
        $otherTodo = Todo::factory()->forUser($other)->create();
        $user->createToken('api');
        $token = requestDeletionToken($user);

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/auth/confirm-account-deletion', [
            'token' => $token,
            'email' => $user->email,
        ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);

        expect(User::find($user->id))->toBeNull()
            ->and(Todo::find($todo->id))->toBeNull()
            ->and(Todo::find($otherTodo->id))->not->toBeNull()
            ->and(User::find($other->id))->not->toBeNull()
            ->and(\Laravel\Sanctum\PersonalAccessToken::where('tokenable_id', $user->id)->count())->toBe(0);
    });

    it('rejects an unknown token', function () {
        $user = makeUser(['password' => Hash::make('secret-password')]);
        requestDeletionToken($user);

        $this->postJson('/api/v1/auth/confirm-account-deletion', [
            'token' => 'not-the-token',
            'email' => $user->email,
        ])
            ->assertStatus(401)
            ->assertJsonPath('errors', ['invalid_token']);

        expect(User::find($user->id))->not->toBeNull();
    });

    it('rejects a token for another email', function () {
        $user = makeUser(['password' => Hash::make('secret-password')]);
        $other = makeUser();
        $token = requestDeletionToken($user);

        $this->postJson('/api/v1/auth/confirm-account-deletion', [
            'token' => $token,
            'email' => $other->email,
        ])->assertStatus(401);

        expect(User::find($user->id))->not->toBeNull()
            ->and(User::find($other->id))->not->toBeNull();
    });

    it('rejects an expired token', function () {
        $user = makeUser(['password' => Hash::make('secret-password')]);
        $token = requestDeletionToken($user);

        $this->travel(61)->minutes();

        $this->postJson('/api/v1/auth/confirm-account-deletion', [
            'token' => $token,
            'email' => $user->email,
        ])->assertStatus(401);

        expect(User::find($user->id))->not->toBeNull();
    });

    it('invalidates the previous link when a new one is requested', function () {
        $user = makeUser(['password' => Hash::make('secret-password')]);
        $first = requestDeletionToken($user);
        requestDeletionToken($user);

        $this->postJson('/api/v1/auth/confirm-account-deletion', [
            'token' => $first,
            'email' => $user->email,
        ])->assertStatus(401);

        expect(User::find($user->id))->not->toBeNull();
    });

    it('does not accept a password reset token', function () {
        $user = makeUser();
        $resetToken = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/confirm-account-deletion', [
            'token' => $resetToken,
            'email' => $user->email,
        ])->assertStatus(401);

        expect(User::find($user->id))->not->toBeNull();
    });

    it('validates the payload', function () {
        $this->postJson('/api/v1/auth/confirm-account-deletion', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['token', 'email']);
    });
});

describe('GET /api/v1/users/me account deletion mode', function () {
    it('reports whether deletion needs an email link', function (bool $flag, string $mailer, ?string $domain, bool $expected) {
        config([
            'auth.policy.account_deletion_confirmation' => $flag,
            'mail.default' => $mailer,
            'services.mailgun.domain' => $domain,
            'services.mailgun.secret' => $domain ? 'key' : null,
        ]);

        $this->actingAs(makeUser(), 'sanctum')
            ->getJson('/api/v1/users/me')
            ->assertStatus(200)
            ->assertJsonPath('meta.account_deletion_requires_email', $expected);
    })->with([
        'flag on, mail configured' => [true, 'mailgun', 'mg.example.com', true],
        'flag on, mailgun empty' => [true, 'mailgun', null, false],
        'flag on, other mailer' => [true, 'smtp', null, true],
        'flag off' => [false, 'mailgun', 'mg.example.com', false],
    ]);
});
