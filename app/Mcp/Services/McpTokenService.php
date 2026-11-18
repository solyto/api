<?php

namespace App\Mcp\Services;

use App\Api\Users\Models\User;
use App\Mcp\Models\McpToken;
use Illuminate\Support\Str;

class McpTokenService
{
    private const string HASH_ALGORITHM = 'sha256';
    private const int TOKEN_LENGTH = 64;

    /** @return array{token: string, model: McpToken} */
    public function issue(User $user): array
    {
        $token = Str::random(self::TOKEN_LENGTH);

        $model = McpToken::create([
            'user_id' => $user->id,
            'token_hash' => $this->hash($token),
            'expires_at' => now()->addDays(config('mcp.token_expiry_days')),
        ]);

        return ['token' => $token, 'model' => $model];
    }

    public function find(string $token): ?McpToken
    {
        return McpToken::with('user')
            ->where('token_hash', $this->hash($token))
            ->where('expires_at', '>', now())
            ->first();
    }

    public function revokeAll(User $user): int
    {
        return McpToken::where('user_id', $user->id)->delete();
    }

    private function hash(string $token): string
    {
        return hash(self::HASH_ALGORITHM, $token);
    }
}
