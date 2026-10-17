<?php

namespace App\Api\Mcp\Commands;

use App\Api\Users\Models\User;
use Illuminate\Console\Command;

class CreateMcpToken extends Command
{
    // Must not start with `auth-token-`: AuthService::refreshToken parses that prefix
    public const string TOKEN_NAME = 'mcp-token';

    protected $signature = 'app:mcp:token {email : Email address of the user the MCP client acts as}';

    protected $description = 'Create a non-expiring Sanctum token for the experimental MCP server and print it once';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("No user found with email: {$email}");

            return self::FAILURE;
        }

        $token = $user->createToken(self::TOKEN_NAME, expiresAt: null)->plainTextToken;

        $this->info("MCP token for {$email} (shown only once, store it now):");
        $this->line($token);

        return self::SUCCESS;
    }
}
