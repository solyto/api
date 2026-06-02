<?php

namespace App\Api\Users\Commands;

use App\Api\Users\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;

class CreateUser extends Command
{
    protected $signature = 'app:users:create';
    protected $description = 'Create a new user';

    public function handle(): void
    {
        $name = $this->ask('Name');
        $email = $this->ask('Email');
        $password = password('Password');
        $role = select('Role', ['user', 'admin', 'super_admin'], default: 'user');
        $verified = $this->confirm('Mark email as verified?', true);

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'in:user,admin,super_admin'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            $this->fail('User creation failed.');
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => $role,
            'email_verified_at' => $verified ? now() : null,
        ]);

        $this->info("User {$email} created successfully.");
    }
}
