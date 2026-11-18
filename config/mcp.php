<?php

return [

    /*
     * Unless this is set, routes/ai.php registers nothing and /mcp does not
     * exist, so self-hosted and production instances are unaffected.
     */
    'enabled' => (bool) env('MCP_ENABLED', false),

    /*
     * MCP tokens live in their own mcp_tokens table, not in Sanctum's.
     * Issue and revoke them with `php artisan app:mcp:token {email} [--revoke]`.
     */
    'token_expiry_days' => 30,

];
