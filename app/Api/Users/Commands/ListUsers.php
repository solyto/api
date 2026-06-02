<?php

namespace App\Api\Users\Commands;

use App\Api\Users\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use function Laravel\Prompts\password;

class ListUsers extends Command
{
    protected $signature = 'app:users:list';
    protected $description = 'List users';

    public function handle(): void
    {
        $this->table(['ID', 'Name', 'Email'], User::all()->map(fn ($user) => [$user->id, $user->name, $user->email]));
    }
}
