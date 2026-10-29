<?php

namespace App\Api\Users\Services;

use App\Api\Users\Exceptions\PersonalAccessTokenRequiredException;
use App\Api\Users\Exceptions\WidgetTokenConflictException;
use App\Api\Users\Mails\UserVerification;
use App\Api\Users\Models\User;
use App\Api\Users\Models\VerificationToken;
use App\Api\Users\Notifications\UserRegisteredNotification;
use App\Shared\Enums\AuthPlatformEnum;
use App\Shared\Notifications\Channels\NtfyChannel;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    use DetectsConcurrencyErrors;

    public function register(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'user',
        ]);

        if (!empty($data['language'])) {
            $user->settings()->update(['language' => $data['language']]);
        }

        $verificationToken = VerificationToken::create([
            'user_id' => $user->id,
            'token' => Str::random(40),
            'expires_at' => now()->addHours(24),
        ]);

        Mail::to($user->email)->send(new UserVerification($user, $verificationToken, $data['platform'] ?? 'web', $data['language'] ?? 'en'));

        Notification::route(NtfyChannel::class, config('services.ntfy.topics.registrations') ?: config('services.ntfy.topic'))->notify(new UserRegisteredNotification());

        return $user;
    }

    public function createToken(User $user, AuthPlatformEnum $platform = AuthPlatformEnum::WEB): array
    {
        $expiresAt = now()->addDays($platform->tokenExpiryDays());
        $token = $user->createToken("auth-token-{$platform->value}", expiresAt: $expiresAt)->plainTextToken;

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'token_expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    public function logoutAll(User $user): void
    {
        $user->tokens()->delete();
    }

    public function refreshToken(User $user): array
    {
        $tokenName = $user->currentAccessToken()->name;
        $platform = AuthPlatformEnum::tryFrom(Str::replace('auth-token-', '', $tokenName))
            ?? AuthPlatformEnum::WEB;

        $user->currentAccessToken()->delete();

        return $this->createToken($user, $platform);
    }

    /**
     * Issue the session's restricted widget token, replacing any earlier one.
     * It shares the session's expiry and is deleted with it (FK cascade).
     *
     * @return array{token: string, token_type: string, token_expires_at: ?string}
     */
    public function createWidgetToken(User $user): array
    {
        // Sanctum types this as PersonalAccessToken, but cookie sessions carry a TransientToken.
        /** @var \Laravel\Sanctum\Contracts\HasAbilities $parent */
        $parent = $user->currentAccessToken();

        if (! $parent instanceof PersonalAccessToken) {
            throw new PersonalAccessTokenRequiredException();
        }

        try {
            $token = DB::transaction(function () use ($user, $parent) {
                PersonalAccessToken::where('parent_token_id', $parent->id)->delete();

                $newToken = $user->createToken('widget-token', ['widget'], $parent->expires_at);
                $newToken->accessToken->forceFill(['parent_token_id' => $parent->id])->save();

                return $newToken->plainTextToken;
            });
        } catch (UniqueConstraintViolationException) {
            throw new WidgetTokenConflictException();
        } catch (QueryException|DeadlockException $e) {
            // On InnoDB two concurrent issues can deadlock on the unique index gap instead.
            if ($this->causedByConcurrencyError($e)) {
                throw new WidgetTokenConflictException();
            }

            throw $e;
        }

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'token_expires_at' => $parent->expires_at?->toIso8601String(),
        ];
    }

    public function verify(array $data): bool
    {
        $user = User::where('id', $data['user_id'])->first();

        if (!$user) {
            return false;
        }

        if ($user->email_verified_at !== null) {
            throw new \RuntimeException('already_verified');
        }

        $verificationToken = VerificationToken::where('user_id', $user->id)->first();

        if (!$verificationToken) {
            return false;
        }

        if (!hash_equals($verificationToken->token, $data['token'])) {
            throw new \RuntimeException('token_mismatch');
        }

        if ($verificationToken->expires_at < now()) {
            throw new \RuntimeException('token_expired');
        }

        $user->update(['email_verified_at' => now()]);
        $verificationToken->delete();

        return true;
    }

    public function resendVerification(string $email, ?string $platform = null): void
    {
        $user = User::where('email', $email)->first();

        if (!$user || $user->email_verified_at !== null) {
            return;
        }

        $existing = VerificationToken::where('user_id', $user->id)->first();

        if ($existing && $existing->updated_at?->gt(now()->subMinutes(5))) {
            return;
        }

        $verificationToken = VerificationToken::updateOrCreate(
            ['user_id' => $user->id],
            ['token' => Str::random(40), 'expires_at' => now()->addHours(24)],
        );

        Mail::to($user->email)->queue(new UserVerification($user, $verificationToken, $platform ?? 'web', $user->settings->language ?? 'en'));
    }

    public function listTokens(User $user): \Illuminate\Support\Collection
    {
        return $user->tokens()->whereNull('parent_token_id')->get()->map(function ($token) use ($user) {
            return [
                'id' => $token->id,
                'name' => $token->name,
                'last_used_at' => $token->last_used_at,
                'created_at' => $token->created_at,
                'is_current' => ($token->id === $user->currentAccessToken()->id),
            ];
        });
    }

    public function revokeToken(User $user, int $tokenId): ?bool
    {
        $token = $user->tokens()->whereNull('parent_token_id')->where('id', $tokenId)->first();

        if (!$token) {
            return null;
        }

        if ($token->id === $user->currentAccessToken()->id) {
            throw new \RuntimeException('Cannot revoke current token. Use logout instead.');
        }

        $token->delete();

        return true;
    }
}
