<?php

namespace App\Api\Users\Services;

use App\Api\Users\Mails\UserVerification;
use App\Api\Users\Models\User;
use App\Api\Users\Models\VerificationToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthService
{
    public function register(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'user',
        ]);

        if (!$user) {
            throw new \RuntimeException('Failed to create user.');
        }

        if (!empty($data['language'])) {
            $user->settings()->update(['language' => $data['language']]);
        }

        $verificationToken = VerificationToken::create([
            'user_id' => $user->id,
            'token' => Str::random(40),
            'expires_at' => now()->addHours(24),
        ]);

        if (!$verificationToken) {
            $user->delete();
            throw new \RuntimeException('Failed to create verification token.');
        }

        Mail::to($user->email)->send(new UserVerification($user, $verificationToken));

        return $user;
    }

    public function createToken(User $user): array
    {
        $expiresAt = now()->addDays(7);
        $token = $user->createToken('auth-token', expiresAt: $expiresAt)->plainTextToken;

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
        $user->currentAccessToken()->delete();

        return $this->createToken($user);
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

    public function listTokens(User $user): \Illuminate\Support\Collection
    {
        return $user->tokens()->get()->map(function ($token) use ($user) {
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
        $token = $user->tokens()->where('id', $tokenId)->first();

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
