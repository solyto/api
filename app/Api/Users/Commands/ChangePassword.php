<?php

namespace App\Api\Users\Commands;

use App\Api\Users\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use function Laravel\Prompts\password;

class ChangePassword extends Command
{
    protected $signature = 'app:users:change-password';
    protected $description = 'Change the password of a user';

    public function handle(): void
    {
        $email = $this->ask('Email');

        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("No user found with email: {$email}");
            return;
        }

        $password = password('New password');
        $confirm  = password('Confirm new password');

        if ($password !== $confirm) {
            $this->error('Passwords do not match.');
            return;
        }

        $validator = Validator::make(['password' => $password], [
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return;
        }

        $user->update(['password' => Hash::make($password)]);
        $user->tokens()->delete();

        $this->info("Password for {$email} changed successfully. All existing sessions have been revoked.");
    }
}
