<?php

use App\Api\Notes\Models\Note;
use App\Api\Todos\Models\Todo;

describe('call_api tool', function () {
    it('acts as the token user: creates a todo and lists it', function () {
        mcpEnable();
        $user = makeUser();
        $token = sanctumToken($user);

        $created = mcpTool($token, 'call_api', ['method' => 'post', 'path' => 'todos', 'body' => ['title' => 'From MCP']]);

        expect($created['isError'])->toBeFalse()
            ->and($created['json']['status'])->toBe(201)
            ->and($created['json']['body']['success'])->toBeTrue()
            ->and($created['json']['body']['data']['title'])->toBe('From MCP');
        expect(Todo::where('user_id', $user->id)->where('title', 'From MCP')->exists())->toBeTrue();

        $listed = mcpTool($token, 'call_api', ['method' => 'GET', 'path' => '/api/v1/todos']);

        expect($listed['json']['status'])->toBe(200)
            ->and(collect($listed['json']['body']['data'])->pluck('title')->all())->toBe(['From MCP']);
    });

    it('passes query parameters through', function () {
        mcpEnable();
        $user = makeUser();
        $token = sanctumToken($user);

        $result = mcpTool($token, 'call_api', ['method' => 'GET', 'path' => 'users/me', 'query' => ['unused' => 'x']]);

        expect($result['json']['status'])->toBe(200)
            ->and($result['json']['body']['data']['email'])->toBe($user->email);
    });

    it('cannot reach another user\'s data', function () {
        mcpEnable();
        $user = makeUser();
        $other = makeUser();
        $todo = Todo::factory()->forUser($other)->create();
        $note = Note::factory()->forUser($other)->create();
        $token = sanctumToken($user);

        $show = mcpTool($token, 'call_api', ['method' => 'GET', 'path' => "todos/{$todo->id}"]);
        $delete = mcpTool($token, 'call_api', ['method' => 'DELETE', 'path' => "notes/{$note->id}"]);
        $list = mcpTool($token, 'call_api', ['method' => 'GET', 'path' => 'todos']);

        expect($show['isError'])->toBeTrue()
            ->and($show['json']['status'])->toBe(403)
            ->and($show['json']['body']['success'])->toBeFalse()
            ->and($delete['json']['status'])->toBe(403)
            ->and($list['json']['body']['data'])->toBe([]);
        expect(Note::whereKey($note->id)->exists())->toBeTrue();
    });

    it('relays the 422 validation envelope', function () {
        mcpEnable();
        $token = sanctumToken(makeUser());

        $result = mcpTool($token, 'call_api', ['method' => 'POST', 'path' => 'todos', 'body' => []]);

        expect($result['isError'])->toBeTrue()
            ->and($result['json']['status'])->toBe(422)
            ->and($result['json']['body']['success'])->toBeFalse()
            ->and($result['json']['body']['errors'])->toHaveKey('title');
    });

    it('relays the 404 envelope for an unknown path', function () {
        mcpEnable();
        $token = sanctumToken(makeUser());

        $result = mcpTool($token, 'call_api', ['method' => 'GET', 'path' => 'does-not-exist']);

        expect($result['isError'])->toBeTrue()
            ->and($result['json']['status'])->toBe(404)
            ->and($result['json']['body']['success'])->toBeFalse();
    });

    it('refuses the endpoints that would sign out or revoke the MCP token, and the token keeps working', function (string $method, string $path) {
        mcpEnable();
        $user = makeUser();
        $token = sanctumToken($user);
        $other = $user->createToken('other')->accessToken;

        $result = mcpTool($token, 'call_api', ['method' => $method, 'path' => $path, 'body' => ['token_id' => $other->id]]);

        expect($result['isError'])->toBeTrue()
            ->and($result['text'])->toContain('not available through MCP')
            ->and($user->tokens()->count())->toBe(2);
        expect(mcpTool($token, 'call_api', ['method' => 'GET', 'path' => 'users/me'])['json']['status'])->toBe(200);
    })->with([
        ['POST', 'auth/logout'],
        ['POST', 'auth/logout-all'],
        ['POST', 'auth/refresh'],
        ['POST', 'auth/revoke-token'],
        ['POST', '/api/v1/auth/logout/'],
        ['POST', 'auth/login'],
        ['PUT', 'users/change-password'],
    ]);

    it('rejects paths that could leave /api/v1', function (string $path) {
        mcpEnable();
        $token = sanctumToken(makeUser());

        $result = mcpTool($token, 'call_api', ['method' => 'GET', 'path' => $path]);

        expect($result['isError'])->toBeTrue()
            ->and($result['text'])->toContain('Invalid path');
    })->with([
        'absolute url' => 'https://example.com/api/v1/todos',
        'protocol relative' => '//example.com/todos',
        'dot segments' => '../../mcp',
        'encoded dots' => '%2e%2e/%2e%2e/mcp',
        'nested dot segments' => 'todos/../../../dav',
        'query string in path' => 'todos?x=1',
        'backslash' => '..\\mcp',
        'empty segment' => 'todos//1',
    ]);

    it('rejects an unsupported HTTP method', function () {
        mcpEnable();
        $token = sanctumToken(makeUser());

        $result = mcpTool($token, 'call_api', ['method' => 'OPTIONS', 'path' => 'todos']);

        expect($result['isError'])->toBeTrue();
    });
});
