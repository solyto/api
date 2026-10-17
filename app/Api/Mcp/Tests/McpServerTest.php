<?php

describe('MCP server route', function () {
    it('is not registered while the flag is off', function () {
        $user = makeUser();

        mcpPost(sanctumToken($user), 'tools/list')->assertStatus(404);
    });

    it('requires a bearer token', function () {
        mcpEnable();

        expectApiError(mcpPost(null, 'tools/list'), 401);
    });

    it('rejects an invalid token', function () {
        mcpEnable();

        expectApiError(mcpPost('1|not-a-real-token', 'tools/list'), 401);
    });

    it('lists the list_endpoints and call_api tools for a valid token', function () {
        mcpEnable();
        $user = makeUser();

        mcpPost(sanctumToken($user), 'tools/list')
            ->assertStatus(200)
            ->assertJsonPath('result.tools.0.name', 'list_endpoints')
            ->assertJsonPath('result.tools.1.name', 'call_api')
            ->assertJsonCount(2, 'result.tools');
    });
});
