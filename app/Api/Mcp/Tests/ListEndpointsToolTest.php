<?php

describe('list_endpoints tool', function () {
    it('returns real api/v1 routes with their OpenAPI summary', function () {
        mcpEnable();
        $token = sanctumToken(makeUser());

        $result = mcpTool($token, 'list_endpoints');

        expect($result['isError'])->toBeFalse()
            ->and($result['json']['endpoints'])->toContain(['method' => 'GET', 'path' => 'todos', 'description' => 'List todos'])
            ->and($result['json']['endpoints'])->toContain(['method' => 'GET', 'path' => 'auth/tokens', 'description' => 'List user tokens'])
            ->and($result['json']['count'])->toBe(count($result['json']['endpoints']));
    });

    it('narrows the list with a case-insensitive filter', function () {
        mcpEnable();
        $token = sanctumToken(makeUser());

        $all = mcpTool($token, 'list_endpoints')['json'];
        $filtered = mcpTool($token, 'list_endpoints', ['filter' => 'NOTES/CATEGORIES'])['json'];

        expect($filtered['count'])->toBeGreaterThan(0)
            ->and($filtered['count'])->toBeLessThan($all['count']);
        foreach ($filtered['endpoints'] as $endpoint) {
            expect($endpoint['path'])->toStartWith('notes/categories');
        }
    });

    it('leaves out the endpoints call_api refuses and HEAD', function () {
        mcpEnable();
        $token = sanctumToken(makeUser());

        $endpoints = collect(mcpTool($token, 'list_endpoints')['json']['endpoints']);

        expect($endpoints->pluck('path'))->not->toContain('auth/logout')
            ->and($endpoints->pluck('path'))->not->toContain('auth/logout-all')
            ->and($endpoints->pluck('path'))->not->toContain('auth/refresh')
            ->and($endpoints->pluck('path'))->not->toContain('auth/revoke-token')
            ->and($endpoints->pluck('path'))->not->toContain('auth/login')
            ->and($endpoints->pluck('path'))->not->toContain('users/change-password')
            ->and($endpoints->pluck('method'))->not->toContain('HEAD');
    });
});
