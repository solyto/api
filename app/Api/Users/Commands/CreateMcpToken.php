<?php

namespace App\Api\Users\Commands;

use App\Api\Users\Models\User;
use App\Mcp\Services\McpTokenService;
use Illuminate\Console\Command;

class CreateMcpToken extends Command
{
    protected $signature = 'app:mcp:token {email} {--revoke : Revoke all of the user\'s MCP tokens instead}';
    protected $description = 'Issue (or revoke) an MCP token for an admin user';

    public function handle(McpTokenService $tokens): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (!$user) {
            $this->error('User not found.');
            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $this->info("Revoked {$tokens->revokeAll($user)} MCP token(s).");
            return self::SUCCESS;
        }

        if (!$user->isAdmin()) {
            $this->error('MCP access is limited to admin users.');
            return self::FAILURE;
        }

        ['token' => $token, 'model' => $model] = $tokens->issue($user);

        $this->info('MCP token (shown once), expires ' . $model->expires_at->toDateString() . ':');
        $this->line($token);

        return self::SUCCESS;
    }
}
