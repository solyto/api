<?php

namespace App\Api\Users\Commands;

use App\Api\Users\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use function Laravel\Prompts\password;

class SearchUser extends Command
{
    protected $signature = 'app:users:search';
    protected $description = 'Search user';

    public function handle(): void
    {
        $query = $this->ask('Search query (Name, email or UID)');

        $this->table(['ID', 'Name', 'Email'], User::where('name', 'like', "%{$query}%")->orWhere('email', 'like', "%{$query}%")->orWhere('id', 'like', "%{$query}%")->get()->map(fn ($user) => [$user->id, $user->name, $user->email]));
    }
}
