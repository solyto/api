<?php

use App\Api\Todos\Models\Todo;
use App\Api\Todos\Models\TodoCategory;
use App\Api\Todos\Models\TodoSubtask;
use App\Api\Todos\Models\TodoWorkspace;
use App\Api\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Todo Factory', function () {
    it('creates a valid todo', function () {
        $todo = Todo::factory()->create();

        expect($todo->title)->toBeString();
        expect($todo->user_id)->not->toBeNull();
    });

    it('creates a completed todo', function () {
        $todo = Todo::factory()->completed()->create();

        expect($todo->is_completed)->toBeTrue();
    });

    it('creates a pending todo', function () {
        $todo = Todo::factory()->pending()->create();

        expect($todo->is_completed)->toBeFalse();
    });

    it('creates a high priority todo', function () {
        $todo = Todo::factory()->highPriority()->create();

        expect($todo->priority)->toBe('high');
    });

    it('creates a low priority todo', function () {
        $todo = Todo::factory()->lowPriority()->create();

        expect($todo->priority)->toBe('low');
    });

    it('creates an overdue todo', function () {
        $todo = Todo::factory()->overdue()->create();

        expect($todo->due_at)->not()->toBeNull();
        expect($todo->due_at)->lessThan(now());
    });

    it('creates a todo due today', function () {
        $todo = Todo::factory()->dueToday()->create();

        expect($todo->due_at)->toEqual(today()->format('Y-m-d'));
    });

    it('creates a todo with no due date', function () {
        $todo = Todo::factory()->noDueDate()->create();

        expect($todo->due_at)->toBeNull();
    });
});

describe('TodoCategory Factory', function () {
    it('creates a valid category', function () {
        $user = User::factory()->create();
        $category = TodoCategory::factory()->create();

        expect($category->title)->toBeString();
        expect($category->user_id)->toBe($user->id);
    });

    it('creates a category with custom title', function () {
        $category = TodoCategory::factory()->withTitle('Work')->create();

        expect($category->title)->toBe('Work');
    });
});

describe('TodoSubtask Factory', function () {
    it('creates a valid subtask', function () {
        $user = User::factory()->create();
        $todo = Todo::factory()->forUser($user)->create();
        $subtask = TodoSubtask::factory()->create(['todo_id' => $todo->id]);

        expect($subtask->title)->toBeString();
        expect($subtask->todo_id)->toBe($todo->id);
    });

    it('creates a completed subtask', function () {
        $subtask = TodoSubtask::factory()->completed()->create();

        expect($subtask->is_completed)->toBeTrue();
    });

    it('creates a pending subtask', function () {
        $subtask = TodoSubtask::factory()->pending()->create();

        expect($subtask->is_completed)->toBeFalse();
    });

    it('creates a subtask with custom title', function () {
        $subtask = TodoSubtask::factory()->withTitle('Review documents')->create();

        expect($subtask->title)->toBe('Review documents');
    });
});

describe('TodoWorkspace Factory', function () {
    it('creates a valid workspace', function () {
        $user = User::factory()->create();
        $workspace = TodoWorkspace::factory()->create();

        expect($workspace->title)->toBeString();
        expect($workspace->user_id)->toBe($user->id);
    });

    it('creates a hideable workspace', function () {
        $workspace = TodoWorkspace::factory()->hideable()->create();

        expect($workspace->is_hideable)->toBeTrue();
    });

    it('creates a non-hideable workspace', function () {
        $workspace = TodoWorkspace::factory()->notHideable()->create();

        expect($workspace->is_hideable)->toBeFalse();
    });

    it('creates a workspace with custom title', function () {
        $workspace = TodoWorkspace::factory()->withTitle('Projects')->create();

        expect($workspace->title)->toBe('Projects');
    });
});

describe('Todo Model', function () {
    it('has correct fillable attributes', function () {
        $todo = new Todo;

        expect($todo->getFillable())->toContain('title');
        expect($todo->getFillable())->toContain('user_id');
        expect($todo->getFillable())->toContain('is_completed');
    });

    it('casts attributes correctly', function () {
        $todo = Todo::factory()->create();

        expect($todo->is_completed)->toBeBoolean();
        expect($todo->due_at)->toBeInstanceOf(\Carbon\Carbon::class);
    });

    it('belongs to a user', function () {
        $user = User::factory()->create();
        $todo = Todo::factory()->forUser($user)->create();

        expect($todo->user->id)->toBe($user->id);
    });

    it('can have category', function () {
        $user = User::factory()->create();
        $category = TodoCategory::factory()->forUser($user)->create();
        $todo = Todo::factory()->forUser($user)->forCategory($category)->create();

        expect($todo->category->id)->toBe($category->id);
    });

    it('can have tags', function () {
        $user = User::factory()->create();
        $todo = Todo::factory()->forUser($user)->create();
        $tag = \App\Api\Tags\Models\Tag::factory()->forUser($user)->create();

        $todo->tags()->attach($tag);
        $todo->load('tags');

        expect($todo->tags)->toHaveCount(1);
    });

    it('can have subtasks', function () {
        $user = User::factory()->create();
        $todo = Todo::factory()->forUser($user)->create();
        TodoSubtask::factory()->forTodo($todo)->create();

        expect($todo->subtasks)->toHaveCount(1);
    });

    it('scopes by user', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Todo::factory()->forUser($user1)->create();
        Todo::factory()->forUser($user2)->create();

        $todos = Todo::forUser($user1)->get();

        expect($todos)->toHaveCount(1);
    });
});
