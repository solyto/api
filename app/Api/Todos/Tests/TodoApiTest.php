<?php

use App\Api\Tags\Models\Tag;
use App\Api\Todos\Models\Todo;
use App\Api\Todos\Models\TodoCategory;
use App\Api\Todos\Models\TodoSubtask;
use App\Api\Todos\Models\TodoWorkspace;
use App\Api\Todos\Services\TodoService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Todo categories CRUD', function () {
    it('lists, stores, shows, updates and deletes categories', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/todos/categories')
            ->assertStatus(200)->assertJsonCount(0, 'data');

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/todos/categories', ['title' => 'Work'])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Work');

        $categoryId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/todos/categories/'.$categoryId)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $categoryId);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/todos/categories/'.$categoryId, ['title' => 'Private'])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Private');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/todos/categories/'.$categoryId)
            ->assertStatus(200);

        expect(TodoCategory::count())->toBe(0);
    });

    it('scopes categories to the authenticated user', function () {
        $user = makeUser();
        $other = makeUser();
        $category = TodoCategory::factory()->forUser($other)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/todos/categories')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/todos/categories/'.$category->id)
            ->assertStatus(403);
    });
});

describe('Todo workspaces CRUD', function () {
    it('lists, stores, shows, updates and deletes workspaces', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/todos/workspaces', ['title' => 'Projects', 'is_hideable' => true])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Projects');

        $workspaceId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/todos/workspaces')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/todos/workspaces/'.$workspaceId, ['title' => 'Renamed'])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Renamed');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/todos/workspaces/'.$workspaceId)
            ->assertStatus(200);

        expect(TodoWorkspace::count())->toBe(0);
    });

    it('attaches and detaches categories to a workspace', function () {
        $user = makeUser();
        $workspace = TodoWorkspace::factory()->forUser($user)->create();
        $category = TodoCategory::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/todos/workspaces/'.$workspace->id.'/categories/attach', [
                'categories' => [$category->id],
            ])
            ->assertStatus(200);

        expect($workspace->fresh()->categories->pluck('id'))->toContain($category->id);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/todos/workspaces/'.$workspace->id.'/categories/detach', [
                'categories' => [$category->id],
            ])
            ->assertStatus(200);

        expect($workspace->fresh()->categories)->toHaveCount(0);
    });
});

describe('Todo CRUD', function () {
    it('lists, stores, shows, updates and deletes todos', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/todos', ['title' => 'Buy milk', 'priority' => 'high'])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Buy milk');

        $todoId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/todos')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/todos/'.$todoId)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $todoId);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/todos/'.$todoId, ['title' => 'Buy milk and bread'])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Buy milk and bread');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/todos/'.$todoId)
            ->assertStatus(200);

        expect(Todo::count())->toBe(0);
    });

    it('completing a todo sets completed_at', function () {
        $user = makeUser();
        $todo = Todo::factory()->forUser($user)->pending()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/todos/'.$todo->id, ['is_completed' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.is_completed', true);

        expect($todo->fresh()->completed_at)->not->toBeNull();
    });

    it('scopes todos to the authenticated user', function () {
        $user = makeUser();
        $other = makeUser();
        Todo::factory()->forUser($other)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/todos')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });

    it('lists due-date todos', function () {
        $user = makeUser();
        Todo::factory()->forUser($user)->create(['due_at' => now()->addDay()->toDateString()]);
        Todo::factory()->forUser($user)->create(['due_at' => now()->subMonths(2)->toDateString()]);
        Todo::factory()->forUser($user)->noDueDate()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/todos/due-date')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    });
});

describe('Todo subtasks', function () {
    it('adds, updates and deletes subtasks', function () {
        $user = makeUser();
        $todo = Todo::factory()->forUser($user)->create();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/todos/'.$todo->id.'/subtasks', ['title' => 'Sub task'])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Sub task');

        $subtaskId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/todos/'.$todo->id.'/subtasks/'.$subtaskId, [
                'title' => 'Renamed sub task',
                'is_completed' => true,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Renamed sub task');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/todos/'.$todo->id.'/subtasks/'.$subtaskId)
            ->assertStatus(200);

        expect(TodoSubtask::count())->toBe(0);
    });
});

describe('TodoService::parse', function () {
    it('extracts #tags from the title and creates missing tags', function () {
        $user = makeUser();

        $data = app(TodoService::class)->parse($user, ['title' => 'Buy milk #groceries']);

        expect($data['title'])->toBe('Buy milk');
        expect($data['tags'])->toHaveCount(1);
        expect(Tag::where('name', 'groceries')->where('user_id', $user->id)->exists())->toBeTrue();
    });

    it('reuses existing tags', function () {
        $user = makeUser();
        $tag = Tag::factory()->forUser($user)->create(['name' => 'work']);

        $data = app(TodoService::class)->parse($user, ['title' => 'Write report #Work']);

        expect($data['tags'])->toBe([$tag->id]);
        expect(Tag::count())->toBe(1);
    });

    it('extracts a /category and creates it if missing', function () {
        $user = makeUser();

        $data = app(TodoService::class)->parse($user, ['title' => 'Plan sprint /work']);

        expect($data['title'])->toBe('Plan sprint');
        expect($data['category_id'])->not->toBeNull();
        expect(TodoCategory::where('title', 'work')->where('user_id', $user->id)->exists())->toBeTrue();
    });

    it('parses due:today, due:tomorrow and explicit dates', function () {
        $user = makeUser();

        $today = app(TodoService::class)->parse($user, ['title' => 'Task due:today']);
        expect($today['due_at'])->toBe(now()->toDateString());

        $tomorrow = app(TodoService::class)->parse($user, ['title' => 'Task due:tomorrow']);
        expect($tomorrow['due_at'])->toBe(now()->addDay()->toDateString());

        $explicit = app(TodoService::class)->parse($user, ['title' => 'Task due:2026-12-24']);
        expect($explicit['due_at'])->toBe('2026-12-24');
    });

    it('parses repeat: and link: directives', function () {
        $user = makeUser();

        $data = app(TodoService::class)->parse($user, [
            'title' => 'Workout due:tomorrow repeat:weekly link:https://example.com',
        ]);

        expect($data['title'])->toBe('Workout');
        expect($data['due_at'])->toBe(now()->addDay()->toDateString());
        expect($data['recurrence_frequency'])->toBe('weekly');
        expect($data['link'])->toBe('https://example.com');
    });

    it('defaults the priority to medium', function () {
        $user = makeUser();

        $data = app(TodoService::class)->parse($user, ['title' => 'Plain task']);

        expect($data['priority'])->toBe('medium');
    });
});
