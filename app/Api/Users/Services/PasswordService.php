<?php

namespace App\Api\Users\Services;

use App\Api\Users\Mails\PasswordResetMail;
use App\Api\Users\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

class PasswordService
{
    public function requestReset(string $email): void
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            return;
        }

        $token = Password::broker()->createToken($user);

        Mail::to($user->email)->send(new PasswordResetMail($user, $token, $user->email));
    }

    public function resetPassword(string $token, string $email, string $password): void
    {
        $status = Password::broker()->reset(
            ['email' => $email, 'password' => $password, 'token' => $token],
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new \RuntimeException(match ($status) {
                Password::INVALID_TOKEN => 'invalid_token',
                Password::INVALID_USER  => 'invalid_token',
                default                 => 'invalid_token',
            });
        }
    }
}
