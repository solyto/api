<?php

use App\Api\DevRequests\Models\DevRequest;
use App\Api\DevRequests\Models\DevRequestComment;
use App\Api\Notes\Models\Note;
use App\Api\Notes\Models\NoteCategory;
use App\Api\Todos\Models\Todo;
use App\Api\Users\Models\User;
use App\Mcp\SolytoServer;
use App\Mcp\Tools\GetDevRequest;
use App\Mcp\Tools\GetNote;
use App\Mcp\Tools\ListDevRequests;
use App\Mcp\Tools\ListTodos;
use App\Mcp\Tools\ListUpcomingEvents;
use App\Mcp\Tools\SearchContacts;
use App\Mcp\Tools\SearchNotes;

beforeEach(fn () => enableMcp());

describe('list-todos', function () {
    it('lists only the user\'s open todos, soonest due first', function () {
        $admin = User::factory()->admin()->create();
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
        $admin = User::factory()->admin()->create();
        Todo::factory()->forUser($admin)->create(['title' => 'Buy milk', 'is_completed' => false]);
        Todo::factory()->forUser($admin)->create(['title' => 'Call mum', 'is_completed' => false]);

        SolytoServer::actingAs($admin)->tool(ListTodos::class, ['query' => 'MILK'])
            ->assertSee('Buy milk')
            ->assertDontSee('Call mum');
    });
});

describe('search-notes and get-note', function () {
    it('finds the user\'s visible notes only', function () {
        $admin = User::factory()->admin()->create();
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
        SolytoServer::actingAs(User::factory()->admin()->create())->tool(SearchNotes::class)->assertHasErrors();
    });

    it('returns a full note', function () {
        $admin = User::factory()->admin()->create();
        $note = Note::factory()->forUser($admin)->create(['title' => 'Recipe', 'content' => 'Full recipe text']);

        SolytoServer::actingAs($admin)->tool(GetNote::class, ['id' => $note->id])
            ->assertOk()
            ->assertSee('Full recipe text');
    });

    it('does not return other users\' notes or hidden notes', function () {
        $admin = User::factory()->admin()->create();
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
        SolytoServer::actingAs(User::factory()->admin()->create())->tool(ListUpcomingEvents::class, ['days' => 365])->assertHasErrors();
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
        $admin = User::factory()->admin()->create();
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
        $admin = User::factory()->admin()->create();
        DevRequest::factory()->create(['title' => 'Dark mode please', 'type' => 'feature', 'status' => 'waiting-for-deployment']);
        DevRequest::factory()->create(['title' => 'Crash on login', 'type' => 'bug', 'status' => 'backlog']);

        SolytoServer::actingAs($admin)->tool(ListDevRequests::class)
            ->assertOk()
            ->assertSee(['Dark mode please', 'Crash on login', 'waiting-for-deployment']);
    });

    it('filters by status, type and text', function () {
        $admin = User::factory()->admin()->create();
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
        SolytoServer::actingAs(User::factory()->admin()->create())->tool(ListDevRequests::class, ['status' => 'whatever'])->assertHasErrors();
    });

    it('returns one dev request with its comments', function () {
        $admin = User::factory()->admin()->create();
        $devRequest = DevRequest::factory()->create(['title' => 'Dark mode please']);
        DevRequestComment::factory()->create(['dev_request_id' => $devRequest->id, 'content' => 'Yes, my eyes hurt']);

        SolytoServer::actingAs($admin)->tool(GetDevRequest::class, ['id' => $devRequest->id])
            ->assertOk()
            ->assertSee(['Dark mode please', 'Yes, my eyes hurt']);

        SolytoServer::actingAs($admin)->tool(GetDevRequest::class, ['id' => $devRequest->id + 1])->assertHasErrors(['Dev request not found.']);
    });
});
