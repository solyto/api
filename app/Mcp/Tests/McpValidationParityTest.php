<?php

use App\Api\DevRequests\Models\DevRequest;
use App\Api\Tags\Models\Tag;
use App\Api\Todos\Models\Todo;
use App\Api\Todos\Models\TodoCategory;
use App\Api\Users\Models\User;
use App\Mcp\SolytoServer;
use App\Mcp\Tools\CreateDevRequest;
use App\Mcp\Tools\CreateTodo;
use App\Mcp\Tools\UpdateTodo;
use Illuminate\Support\Facades\Notification;

// The create-todo, update-todo and create-dev-request tools validate with the REST endpoint's FormRequest rules.
// Every case runs through both entry points, so the two accept exactly the same input.

beforeEach(fn () => enableMcp());

// Placeholders for ids that only exist once the test has created its user.
function parityPayload(array $payload, User $user): array
{
    return array_map(fn ($value) => match ($value) {
        'own-category' => TodoCategory::factory()->forUser($user)->create()->id,
        'foreign-category' => TodoCategory::factory()->forUser(makeUser())->create()->id,
        ['own-tag'] => [Tag::factory()->forUser($user)->create()->id],
        ['foreign-tag'] => [Tag::factory()->forUser(makeUser())->create()->id],
        default => $value,
    }, $payload);
}

function invalidTodoFields(): array
{
    return [
        'title not a string' => [['title' => ['A']], 'title'],
        'title too long' => [['title' => str_repeat('a', 256)], 'title'],
        'description not a string' => [['description' => ['A']], 'description'],
        'description too long' => [['description' => str_repeat('a', 1001)], 'description'],
        'link too long' => [['link' => str_repeat('a', 2049)], 'link'],
        'unknown priority' => [['priority' => 'urgent'], 'priority'],
        'unknown status' => [['status' => 'done'], 'status'],
        'progress below 0' => [['progress' => -1], 'progress'],
        'progress above 100' => [['progress' => 101], 'progress'],
        'progress not an integer' => [['progress' => 'half'], 'progress'],
        'unknown effort' => [['effort' => 'huge'], 'effort'],
        'another user\'s category' => [['category_id' => 'foreign-category'], 'category_id'],
        'tags not a list' => [['tags' => 'errands'], 'tags'],
        'another user\'s tag' => [['tags' => ['foreign-tag']], 'tags.0'],
        'unknown recurrence' => [['recurrence_frequency' => 'hourly', 'due_at' => '2026-12-01'], 'recurrence_frequency'],
        'recurrence interval 0' => [['recurrence_interval' => 0], 'recurrence_interval'],
        'recurrence interval above 365' => [['recurrence_interval' => 366], 'recurrence_interval'],
    ];
}

describe('create-todo and POST /todos', function () {
    $valid = [
        'title' => 'Buy milk',
        'description' => 'Two litres',
        'link' => 'https://example.com',
        'priority' => 'high',
        'due_at' => '2026-12-01',
        'status' => 'in-progress',
        'progress' => 50,
        'effort' => 'low',
        'category_id' => 'own-category',
        'tags' => ['own-tag'],
        'recurrence_frequency' => 'weekly',
        'recurrence_interval' => 2,
        'recurrence_ends_at' => '2027-06-01',
    ];

    it('accepts the same valid todo', function () use ($valid) {
        $admin = mcpAdmin();

        SolytoServer::actingAs($admin)->tool(CreateTodo::class, parityPayload($valid, $admin))->assertOk();
        expectApiSuccess($this->actingAs($admin, 'sanctum')->postJson('/api/v1/todos', parityPayload($valid, $admin)), 201);

        expect(Todo::forUser($admin->id)->count())->toBe(2);
    });

    it('rejects the same invalid field', function (array $invalid, string $field) {
        $admin = mcpAdmin();
        $payload = array_merge(['title' => 'Buy milk'], $invalid);

        SolytoServer::actingAs($admin)->tool(CreateTodo::class, parityPayload($payload, $admin))->assertHasErrors();
        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/todos', parityPayload($payload, $admin));

        expectApiError($response, 422);
        $response->assertJsonValidationErrors($field);
        expect(Todo::forUser($admin->id)->count())->toBe(0);
    })->with(invalidTodoFields());

    it('rejects a todo without a title', function () {
        $admin = mcpAdmin();

        SolytoServer::actingAs($admin)->tool(CreateTodo::class, ['description' => 'A'])->assertHasErrors();
        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/todos', ['description' => 'A']);

        expectApiError($response, 422);
        $response->assertJsonValidationErrors('title');
    });

    it('takes only YYYY-MM-DD dates and a due date for recurring todos over MCP', function (array $payload) {
        $admin = mcpAdmin();

        SolytoServer::actingAs($admin)->tool(CreateTodo::class, ['title' => 'A', ...$payload])->assertHasErrors();

        expect(Todo::forUser($admin->id)->count())->toBe(0);
    })->with([
        'due_at as a word' => [['due_at' => 'tomorrow']],
        'due_at as DD.MM.YYYY' => [['due_at' => '01.12.2026']],
        'recurrence_ends_at as a word' => [['recurrence_ends_at' => 'tomorrow']],
        'recurring without due_at' => [['recurrence_frequency' => 'daily']],
    ]);
});

describe('update-todo and PUT /todos/{todo}', function () {
    $valid = [
        'title' => 'New title',
        'description' => null,
        'link' => null,
        'priority' => 'low',
        'due_at' => 'tomorrow',
        'status' => 'waiting',
        'progress' => null,
        'effort' => null,
        'is_completed' => false,
        'category_id' => 'own-category',
        'tags' => ['own-tag'],
        'recurrence_frequency' => 'monthly',
        'recurrence_interval' => 3,
        'recurrence_ends_at' => '2027-06-01',
    ];

    it('accepts the same valid change', function () use ($valid) {
        $admin = mcpAdmin();
        [$overMcp, $overRest] = Todo::factory()->forUser($admin)->count(2)->create();

        SolytoServer::actingAs($admin)->tool(UpdateTodo::class, ['id' => $overMcp->id, ...parityPayload($valid, $admin)])->assertOk();
        expectApiSuccess($this->actingAs($admin, 'sanctum')->putJson('/api/v1/todos/'.$overRest->id, parityPayload($valid, $admin)));

        expect($overMcp->fresh()->title)->toBe('New title')
            ->and($overRest->fresh()->title)->toBe('New title');
    });

    it('rejects the same invalid field', function (array $invalid, string $field) {
        $admin = mcpAdmin();
        $todo = Todo::factory()->forUser($admin)->create(['title' => 'Old title']);

        SolytoServer::actingAs($admin)->tool(UpdateTodo::class, ['id' => $todo->id, ...parityPayload($invalid, $admin)])->assertHasErrors();
        $response = $this->actingAs($admin, 'sanctum')->putJson('/api/v1/todos/'.$todo->id, parityPayload($invalid, $admin));

        expectApiError($response, 422);
        $response->assertJsonValidationErrors($field);
        expect($todo->fresh()->title)->toBe('Old title');
    })->with([
        ...invalidTodoFields(),
        'title null' => [['title' => null], 'title'],
        'completion not a boolean' => [['is_completed' => 'maybe'], 'is_completed'],
        'due_at not a string' => [['due_at' => 20261201], 'due_at'],
        'recurrence_ends_at not a string' => [['recurrence_ends_at' => ['2027']], 'recurrence_ends_at'],
    ]);

    it('needs the todo id as a UUID over MCP', function (array $payload) {
        SolytoServer::actingAs(mcpAdmin())->tool(UpdateTodo::class, ['title' => 'A', ...$payload])->assertHasErrors();
    })->with([
        'missing' => [[]],
        'not a UUID' => [['id' => '42']],
    ]);
});

describe('create-dev-request and POST /dev-requests', function () {
    beforeEach(fn () => Notification::fake());

    $valid = [
        'type' => 'bug',
        'title' => 'Crash on save',
        'description' => 'Saving a note crashes',
        'priority' => 5,
        'url' => 'https://app.example.com/notes',
    ];

    it('accepts the same valid dev request', function () use ($valid) {
        $admin = mcpAdmin();

        SolytoServer::actingAs($admin)->tool(CreateDevRequest::class, $valid)->assertOk();
        expectApiSuccess($this->actingAs($admin, 'sanctum')->postJson('/api/v1/dev-requests', $valid), 201);

        expect(DevRequest::count())->toBe(2);
    });

    it('rejects the same invalid field', function (array $invalid, string $field) use ($valid) {
        $admin = mcpAdmin();
        $payload = array_merge($valid, $invalid);

        SolytoServer::actingAs($admin)->tool(CreateDevRequest::class, $payload)->assertHasErrors();
        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/dev-requests', $payload);

        expectApiError($response, 422);
        $response->assertJsonValidationErrors($field);
        expect(DevRequest::count())->toBe(0);
    })->with([
        'no type' => [['type' => null], 'type'],
        'unknown type' => [['type' => 'question'], 'type'],
        'no title' => [['title' => null], 'title'],
        'title too long' => [['title' => str_repeat('a', 256)], 'title'],
        'no description' => [['description' => null], 'description'],
        'description too long' => [['description' => str_repeat('a', 65536)], 'description'],
        'priority 0' => [['priority' => 0], 'priority'],
        'priority above 5' => [['priority' => 6], 'priority'],
        'priority not an integer' => [['priority' => 'high'], 'priority'],
        'url not a URL' => [['url' => 'not a url'], 'url'],
        'url too long' => [['url' => 'https://example.com/'.str_repeat('a', 236)], 'url'],
    ]);
});
