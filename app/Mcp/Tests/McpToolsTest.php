<?php

use App\Api\DevRequests\Models\DevRequest;
use App\Api\DevRequests\Models\DevRequestComment;
use App\Api\Notes\Models\Note;
use App\Api\Notes\Models\NoteCategory;
use App\Api\Tags\Models\Tag;
use App\Api\Todos\Models\Todo;
use App\Api\Todos\Models\TodoCategory;
use App\Api\Users\Models\User;
use App\Mcp\SolytoServer;
use App\Mcp\Tools\CreateDevRequest;
use App\Mcp\Tools\CreateTodo;
use App\Mcp\Tools\GetDevRequest;
use App\Mcp\Tools\GetNote;
use App\Mcp\Tools\ListDevRequests;
use App\Mcp\Tools\ListTodos;
use App\Mcp\Tools\ListTodoTagsAndCategories;
use App\Mcp\Tools\ListUpcomingEvents;
use App\Mcp\Tools\SearchContacts;
use App\Mcp\Tools\SearchNotes;
use App\Mcp\Tools\UpdateTodo;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => enableMcp());

describe('list-todos', function () {
    it('lists only the user\'s open todos, soonest due first', function () {
        $admin = mcpAdmin();
        Todo::factory()->forUser($admin)->create(['title' => 'Later', 'due_at' => now()->addWeek(), 'is_completed' => false]);
        Todo::factory()->forUser($admin)->create(['title' => 'Sooner', 'due_at' => now()->addDay(), 'is_completed' => false]);
        Todo::factory()->forUser($admin)->create(['title' => 'Done', 'is_completed' => true]);
        Todo::factory()->forUser(makeUser())->create(['title' => 'Someone else\'s', 'is_completed' => false]);

        SolytoServer::actingAs($admin)->tool(ListTodos::class)
            ->assertOk()
            ->assertSee(['Sooner', 'Later'])
            ->assertDontSee(['Done', 'Someone else']);
    });

    it('filters by text', function () {
        $admin = mcpAdmin();
        Todo::factory()->forUser($admin)->create(['title' => 'Buy milk', 'is_completed' => false]);
        Todo::factory()->forUser($admin)->create(['title' => 'Call mum', 'is_completed' => false]);

        SolytoServer::actingAs($admin)->tool(ListTodos::class, ['query' => 'MILK'])
            ->assertSee('Buy milk')
            ->assertDontSee('Call mum');
    });
});

describe('list-todo-tags-and-categories', function () {
    it('lists only the user\'s tags and categories', function () {
        $admin = mcpAdmin();
        Tag::factory()->forUser($admin)->create(['name' => 'errands']);
        TodoCategory::factory()->forUser($admin)->create(['title' => 'Home']);
        Tag::factory()->forUser(makeUser())->create(['name' => 'foreign-tag']);
        TodoCategory::factory()->forUser(makeUser())->create(['title' => 'Foreign category']);

        SolytoServer::actingAs($admin)->tool(ListTodoTagsAndCategories::class)
            ->assertOk()
            ->assertSee(['errands', 'Home'])
            ->assertDontSee(['foreign-tag', 'Foreign category']);
    });
});

describe('create-todo', function () {
    it('creates a todo for the user', function () {
        $admin = mcpAdmin();
        $category = TodoCategory::factory()->forUser($admin)->create();
        $tag = Tag::factory()->forUser($admin)->create();

        SolytoServer::actingAs($admin)->tool(CreateTodo::class, [
            'title' => 'Buy milk',
            'due_at' => '2026-12-01',
            'category_id' => $category->id,
            'tags' => [$tag->id],
        ])->assertOk()->assertSee('Buy milk');

        $todo = Todo::forUser($admin->id)->sole();
        expect($todo->title)->toBe('Buy milk')
            ->and($todo->priority)->toBe('medium')
            ->and($todo->due_at->toDateString())->toBe('2026-12-01')
            ->and($todo->category_id)->toBe($category->id)
            ->and($todo->tags->pluck('id')->all())->toBe([$tag->id]);
    });

    it('does not create tags or categories from the title', function () {
        $admin = mcpAdmin();

        SolytoServer::actingAs($admin)->tool(CreateTodo::class, ['title' => 'Read #book /reading due:tomorrow'])->assertOk();

        expect(Todo::forUser($admin->id)->sole()->title)->toBe('Read #book /reading due:tomorrow')
            ->and(Tag::forUser($admin->id)->count())->toBe(0)
            ->and(TodoCategory::forUser($admin->id)->count())->toBe(0);
    });

    it('rejects other users\' categories and tags', function () {
        $admin = mcpAdmin();

        SolytoServer::actingAs($admin)->tool(CreateTodo::class, ['title' => 'A', 'category_id' => TodoCategory::factory()->forUser(makeUser())->create()->id])
            ->assertHasErrors();
        SolytoServer::actingAs($admin)->tool(CreateTodo::class, ['title' => 'B', 'tags' => [Tag::factory()->forUser(makeUser())->create()->id]])
            ->assertHasErrors();

        expect(Todo::forUser($admin->id)->count())->toBe(0);
    });

    it('rejects invalid input', function () {
        $admin = mcpAdmin();

        SolytoServer::actingAs($admin)->tool(CreateTodo::class, [])->assertHasErrors();
        SolytoServer::actingAs($admin)->tool(CreateTodo::class, ['title' => 'A', 'due_at' => 'tomorrow'])->assertHasErrors();
        SolytoServer::actingAs($admin)->tool(CreateTodo::class, ['title' => 'A', 'recurrence_frequency' => 'daily'])->assertHasErrors();

        expect(Todo::forUser($admin->id)->count())->toBe(0);
    });

    it('ignores fields it does not accept', function () {
        $admin = mcpAdmin();
        $other = makeUser();

        SolytoServer::actingAs($admin)->tool(CreateTodo::class, ['title' => 'Mine', 'user_id' => $other->id, 'is_completed' => true])->assertOk();

        expect(Todo::forUser($admin->id)->sole()->is_completed)->toBeFalse()
            ->and(Todo::forUser($other->id)->count())->toBe(0);
    });
});

describe('update-todo', function () {
    it('updates only the given fields', function () {
        $admin = mcpAdmin();
        $todo = Todo::factory()->forUser($admin)->create(['title' => 'Old title', 'priority' => 'low', 'description' => 'Keep me']);

        SolytoServer::actingAs($admin)->tool(UpdateTodo::class, ['id' => $todo->id, 'title' => 'New title', 'priority' => 'high'])
            ->assertOk()
            ->assertSee('New title');

        $todo->refresh();
        expect($todo->title)->toBe('New title')
            ->and($todo->priority)->toBe('high')
            ->and($todo->description)->toBe('Keep me');
    });

    it('sets the category and replaces the tags', function () {
        $admin = mcpAdmin();
        $category = TodoCategory::factory()->forUser($admin)->create();
        $tag = Tag::factory()->forUser($admin)->create();
        $todo = Todo::factory()->forUser($admin)->create();
        $todo->tags()->attach(Tag::factory()->forUser($admin)->create());

        SolytoServer::actingAs($admin)->tool(UpdateTodo::class, ['id' => $todo->id, 'category_id' => $category->id, 'tags' => [$tag->id]])
            ->assertOk();

        $todo->refresh();
        expect($todo->category_id)->toBe($category->id)
            ->and($todo->tags->pluck('id')->all())->toBe([$tag->id]);
    });

    it('rejects other users\' categories and tags', function () {
        $admin = mcpAdmin();
        $todo = Todo::factory()->forUser($admin)->create();

        SolytoServer::actingAs($admin)->tool(UpdateTodo::class, ['id' => $todo->id, 'category_id' => TodoCategory::factory()->forUser(makeUser())->create()->id])
            ->assertHasErrors();
        SolytoServer::actingAs($admin)->tool(UpdateTodo::class, ['id' => $todo->id, 'tags' => [Tag::factory()->forUser(makeUser())->create()->id]])
            ->assertHasErrors();
    });

    it('does not update other users\' todos', function () {
        $admin = mcpAdmin();
        $todo = Todo::factory()->forUser(makeUser())->create(['title' => 'Not yours']);

        SolytoServer::actingAs($admin)->tool(UpdateTodo::class, ['id' => $todo->id, 'title' => 'Hijacked'])
            ->assertHasErrors(['Todo not found.']);

        expect($todo->refresh()->title)->toBe('Not yours');
    });

    it('rejects invalid values', function () {
        $admin = mcpAdmin();
        $todo = Todo::factory()->forUser($admin)->create();

        SolytoServer::actingAs($admin)->tool(UpdateTodo::class, ['id' => $todo->id, 'status' => 'whatever'])->assertHasErrors();
        SolytoServer::actingAs($admin)->tool(UpdateTodo::class, ['title' => 'No id'])->assertHasErrors();
    });
});

describe('search-notes and get-note', function () {
    it('finds the user\'s visible notes only', function () {
        $admin = mcpAdmin();
        $hidden = NoteCategory::factory()->forUser($admin)->hidden()->create();
        Note::factory()->forUser($admin)->create(['title' => 'Holiday plans', 'content' => 'Lisbon in spring']);
        Note::factory()->forUser($admin)->create(['title' => 'Diary', 'content' => 'Lisbon secret', 'category_id' => $hidden->id]);
        Note::factory()->forUser(makeUser())->create(['title' => 'Foreign', 'content' => 'Lisbon too']);

        SolytoServer::actingAs($admin)->tool(SearchNotes::class, ['query' => 'lisbon'])
            ->assertOk()
            ->assertSee('Holiday plans')
            ->assertDontSee(['Diary', 'Foreign']);
    });

    it('requires a query', function () {
        SolytoServer::actingAs(mcpAdmin())->tool(SearchNotes::class)->assertHasErrors();
    });

    it('returns a full note', function () {
        $admin = mcpAdmin();
        $note = Note::factory()->forUser($admin)->create(['title' => 'Recipe', 'content' => 'Full recipe text']);

        SolytoServer::actingAs($admin)->tool(GetNote::class, ['id' => $note->id])
            ->assertOk()
            ->assertSee('Full recipe text');
    });

    it('does not return other users\' notes or hidden notes', function () {
        $admin = mcpAdmin();
        $hidden = NoteCategory::factory()->forUser($admin)->hidden()->create();
        $hiddenNote = Note::factory()->forUser($admin)->create(['category_id' => $hidden->id]);
        $foreignNote = Note::factory()->forUser(makeUser())->create();

        SolytoServer::actingAs($admin)->tool(GetNote::class, ['id' => $hiddenNote->id])->assertHasErrors(['Note not found.']);
        SolytoServer::actingAs($admin)->tool(GetNote::class, ['id' => $foreignNote->id])->assertHasErrors(['Note not found.']);
    });
});

describe('list-upcoming-events', function () {
    it('lists events inside the window only', function () {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $calendarId = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/calendars', ['name' => 'Work'])->json('data.id');

        foreach (['Standup' => 1, 'Offsite' => 20] as $title => $inDays) {
            $start = now()->addDays($inDays)->setTime(10, 0);
            $this->actingAs($admin, 'sanctum')->postJson('/api/v1/calendars/' . $calendarId . '/events', [
                'title' => $title,
                'start_date' => $start->format('Y-m-d H:i:s'),
                'end_date' => $start->copy()->addHour()->format('Y-m-d H:i:s'),
                'is_all_day' => false,
                'calendar_id' => $calendarId,
            ])->assertStatus(201);
        }

        SolytoServer::actingAs($admin)->tool(ListUpcomingEvents::class, ['days' => 7])
            ->assertOk()
            ->assertSee('Standup')
            ->assertDontSee('Offsite');
    });

    it('caps the window', function () {
        SolytoServer::actingAs(mcpAdmin())->tool(ListUpcomingEvents::class, ['days' => 365])->assertHasErrors();
    });
});

describe('search-contacts', function () {
    it('finds the user\'s contacts by name or email', function () {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $addressBookId = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/address-books', ['name' => 'Work'])->json('data.id');

        foreach ([['Jane', 'Roe', 'jane@example.com'], ['John', 'Doe', 'john@acme.test']] as [$first, $last, $email]) {
            $this->actingAs($admin, 'sanctum')->postJson('/api/v1/address-books/' . $addressBookId . '/contacts', [
                'address_book_id' => $addressBookId,
                'first_name' => $first,
                'last_name' => $last,
                'email' => json_encode([['value' => $email, 'type' => 'INTERNET']]),
            ])->assertStatus(201);
        }

        SolytoServer::actingAs($admin)->tool(SearchContacts::class, ['query' => 'acme'])
            ->assertOk()
            ->assertSee('John')
            ->assertDontSee('Jane');

        SolytoServer::actingAs(makeUser())->tool(SearchContacts::class, ['query' => 'acme'])
            ->assertDontSee('John');
    });
});

describe('list-dev-requests and get-dev-request', function () {
    it('never reveals who wrote a dev request or comment', function () {
        $admin = mcpAdmin();
        $author = User::factory()->create(['name' => 'Alice Author']);
        $commenter = User::factory()->create(['name' => 'Carl Commenter']);
        $devRequest = DevRequest::factory()->create(['title' => 'Dark mode please', 'created_by_user_id' => $author->id]);
        DevRequestComment::factory()->create(['dev_request_id' => $devRequest->id, 'user_id' => $commenter->id, 'content' => 'Yes, my eyes hurt']);

        SolytoServer::actingAs($admin)->tool(ListDevRequests::class)
            ->assertSee('Dark mode please')
            ->assertDontSee([$author->id, 'created_by_user_id']);

        SolytoServer::actingAs($admin)->tool(GetDevRequest::class, ['id' => $devRequest->id])
            ->assertSee(['Dark mode please', 'Yes, my eyes hurt'])
            ->assertDontSee([$author->id, $commenter->id, 'Alice Author', 'Carl Commenter', 'created_by_user_id']);
    });

    it('lists dev requests from all users with their real status', function () {
        $admin = mcpAdmin();
        DevRequest::factory()->create(['title' => 'Dark mode please', 'type' => 'feature', 'status' => 'waiting-for-deployment']);
        DevRequest::factory()->create(['title' => 'Crash on login', 'type' => 'bug', 'status' => 'backlog']);
        DevRequest::factory()->create(['title' => 'Export as CSV', 'type' => 'feature', 'status' => 'waiting-for-user']);

        SolytoServer::actingAs($admin)->tool(ListDevRequests::class)
            ->assertOk()
            ->assertSee(['Dark mode please', 'Crash on login', 'waiting-for-deployment', 'Export as CSV', 'waiting-for-user']);
    });

    it('filters by status, type and text', function () {
        $admin = mcpAdmin();
        DevRequest::factory()->create(['title' => 'Dark mode please', 'type' => 'feature', 'status' => 'backlog']);
        DevRequest::factory()->create(['title' => 'Crash on login', 'type' => 'bug', 'status' => 'backlog']);
        DevRequest::factory()->create(['title' => 'Old crash', 'type' => 'bug', 'status' => 'completed']);

        SolytoServer::actingAs($admin)->tool(ListDevRequests::class, ['type' => 'bug', 'status' => 'backlog'])
            ->assertSee('Crash on login')
            ->assertDontSee(['Dark mode please', 'Old crash']);

        SolytoServer::actingAs($admin)->tool(ListDevRequests::class, ['query' => 'DARK'])
            ->assertSee('Dark mode please')
            ->assertDontSee('Crash on login');
    });

    it('rejects unknown statuses', function () {
        SolytoServer::actingAs(mcpAdmin())->tool(ListDevRequests::class, ['status' => 'whatever'])->assertHasErrors();
    });

    it('returns one dev request with its comments', function () {
        $admin = mcpAdmin();
        $devRequest = DevRequest::factory()->create(['title' => 'Dark mode please']);
        DevRequestComment::factory()->create(['dev_request_id' => $devRequest->id, 'content' => 'Yes, my eyes hurt']);

        SolytoServer::actingAs($admin)->tool(GetDevRequest::class, ['id' => $devRequest->id])
            ->assertOk()
            ->assertSee(['Dark mode please', 'Yes, my eyes hurt']);

        SolytoServer::actingAs($admin)->tool(GetDevRequest::class, ['id' => $devRequest->id + 1])->assertHasErrors(['Dev request not found.']);
    });
});

describe('create-dev-request', function () {
    beforeEach(fn () => Notification::fake());

    it('creates a dev request as the user', function () {
        $admin = mcpAdmin();

        SolytoServer::actingAs($admin)->tool(CreateDevRequest::class, [
            'type' => 'bug', 'title' => 'Crash on save', 'description' => 'Saving a note crashes', 'priority' => 4,
        ])->assertOk()->assertSee('Crash on save');

        $devRequest = DevRequest::sole();
        expect($devRequest->created_by_user_id)->toBe($admin->id)
            ->and($devRequest->type)->toBe('bug')
            ->and($devRequest->priority)->toBe(4);
    });

    it('rejects invalid input and ignores fields it does not accept', function () {
        $admin = mcpAdmin();

        SolytoServer::actingAs($admin)->tool(CreateDevRequest::class, ['type' => 'bug', 'title' => 'No description'])->assertHasErrors();
        SolytoServer::actingAs($admin)->tool(CreateDevRequest::class, ['type' => 'question', 'title' => 'A', 'description' => 'B'])->assertHasErrors();
        expect(DevRequest::count())->toBe(0);

        SolytoServer::actingAs($admin)->tool(CreateDevRequest::class, [
            'type' => 'feature', 'title' => 'A', 'description' => 'B', 'status' => 'completed', 'created_by_user_id' => makeUser()->id,
        ])->assertOk();

        expect(DevRequest::sole()->created_by_user_id)->toBe($admin->id)
            ->and(DevRequest::sole()->status)->not->toBe('completed');
    });

    it('shares the REST endpoint\'s hourly limit', function () {
        $admin = mcpAdmin();
        $payload = ['type' => 'feature', 'title' => 'Dark mode', 'description' => 'Add a dark theme'];

        for ($i = 0; $i < 9; $i++) {
            $this->actingAs($admin, 'sanctum')->postJson('/api/v1/dev-requests', $payload)->assertStatus(201);
        }

        SolytoServer::actingAs($admin)->tool(CreateDevRequest::class, $payload)->assertOk();
        SolytoServer::actingAs($admin)->tool(CreateDevRequest::class, $payload)->assertHasErrors(['Too many dev requests, try again later.']);
        expectApiError($this->actingAs($admin, 'sanctum')->postJson('/api/v1/dev-requests', $payload), 429);
        expect(DevRequest::count())->toBe(10);

        $this->travel(61)->minutes();
        SolytoServer::actingAs($admin)->tool(CreateDevRequest::class, $payload)->assertOk();
        expect(DevRequest::count())->toBe(11);
    });
});
