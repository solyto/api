<?php

use App\Api\CheckIn\Models\CheckIn;
use App\Api\Clipboard\Models\Clipboard;
use App\Api\DevRequests\Models\DevRequest;
use App\Api\Feeds\Models\FeedSubscription;
use App\Api\Finances\Models\Budget;
use App\Api\Finances\Models\BudgetValue;
use App\Api\Finances\Models\WealthField;
use App\Api\Finances\Models\WealthValue;
use App\Api\Libraries\Models\LibraryBook;
use App\Api\Libraries\Models\LibraryBookGenre;
use App\Api\Libraries\Models\LibraryGame;
use App\Api\Libraries\Models\LibraryGameGenre;
use App\Api\Libraries\Models\LibraryMovie;
use App\Api\Libraries\Models\LibraryMovieGenre;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Libraries\Models\LibraryMusicGenre;
use App\Api\Notes\Models\Note;
use App\Api\Notes\Models\NoteCategory;
use App\Api\Tags\Models\Tag;
use App\Api\TimeTracking\Models\TimeTrackingCategory;
use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\TimeTracking\Models\TimeTrackingProject;
use App\Api\Todos\Models\Todo;
use App\Api\Todos\Models\TodoCategory;
use App\Api\Todos\Models\TodoSubtask;
use App\Api\Todos\Models\TodoWorkspace;
use App\Plugins\Ai\Services\AiModelSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\FakeCalDavServer;

uses(RefreshDatabase::class);

describe('Client contract', function () {
    // Since 1.0.15. solyto/app src/routes/setup/+page.svelte:37
    it('keeps app and version at the top level of health', function () {
        $response = $this->getJson('/api/v1/health')
            ->assertStatus(200)
            ->assertJsonPath('app', 'solyto');

        expect($response->json('version'))->toBeString()->not->toBeEmpty();
    });

    it('keeps the standard envelope on health', function () {
        $response = $this->getJson('/api/v1/health')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'OK')
            ->assertJsonPath('data.app', 'solyto');

        expect($response->json('data.version'))->toBeString()->not->toBeEmpty()
            ->and($response->json('data.version'))->toBe($response->json('version'));
    });

    // solyto/app compares this against its own version to warn about an outdated server.
    it('reports the version from the VERSION file on health', function () {
        $this->getJson('/api/v1/health')
            ->assertStatus(200)
            ->assertJsonPath('version', trim(file_get_contents(base_path('VERSION'))));
    });

    // Since 1.0.15. solyto/app src/lib/state/Notes.svelte.ts:83-84
    it('leaves hidden and system note categories and their descendants out of the plain list', function () {
        $user = makeUser();
        $journal = NoteCategory::forUser($user->id)->where('title', NoteCategory::JOURNAL_TITLE)->firstOrFail();
        NoteCategory::factory()->forUser($user)->withParent($journal)->withTitle('Journal child')->create();
        $hidden = NoteCategory::factory()->forUser($user)->hidden()->withTitle('Hidden')->create();
        $hiddenChild = NoteCategory::factory()->forUser($user)->withParent($hidden)->withTitle('Hidden child')->create();
        NoteCategory::factory()->forUser($user)->withParent($hiddenChild)->withTitle('Hidden grandchild')->create();
        $visible = NoteCategory::factory()->forUser($user)->withTitle('Visible')->create();
        $visibleHiddenChild = NoteCategory::factory()->forUser($user)->withParent($visible)->hidden()->withTitle('Visible hidden child')->create();
        NoteCategory::factory()->forUser($user)->withParent($visibleHiddenChild)->withTitle('Visible hidden grandchild')->create();
        NoteCategory::factory()->forUser($user)->withParent($visible)->withTitle('Visible child')->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notes/categories')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Visible')
            ->assertJsonCount(1, 'data.0.children')
            ->assertJsonPath('data.0.children.0.title', 'Visible child')
            ->assertJsonCount(0, 'data.0.children.0.children');

        expect($response->getContent())
            ->not->toContain(NoteCategory::JOURNAL_TITLE)
            ->not->toContain('Journal child')
            ->not->toContain('Hidden');
    });

    // Since 1.0.15. solyto/app src/lib/state/Notes.svelte.ts:53-56
    it('leaves notes in hidden and system categories and their descendants out of the plain list', function () {
        $user = makeUser();
        $journal = NoteCategory::forUser($user->id)->where('title', NoteCategory::JOURNAL_TITLE)->firstOrFail();
        $hidden = NoteCategory::factory()->forUser($user)->hidden()->create();
        $hiddenChild = NoteCategory::factory()->forUser($user)->withParent($hidden)->create();
        $visible = NoteCategory::factory()->forUser($user)->create();

        Note::factory()->forUser($user)->create(['title' => '2026-10-14', 'category_id' => $journal->id]);
        Note::factory()->forUser($user)->create(['title' => 'In hidden', 'category_id' => $hidden->id]);
        Note::factory()->forUser($user)->create(['title' => 'In hidden child', 'category_id' => $hiddenChild->id]);
        Note::factory()->forUser($user)->create(['title' => 'In visible', 'category_id' => $visible->id]);
        Note::factory()->forUser($user)->create(['title' => 'Uncategorized', 'category_id' => null]);

        $titles = collect(
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/notes')
                ->assertStatus(200)
                ->json('data')
        )->pluck('title')->all();

        expect($titles)->toEqualCanonicalizing(['In visible', 'Uncategorized']);
    });

    // Since 1.0.15. solyto/app src/lib/config/apiRoutes.ts:8-348, src/routes/setup/+page.svelte:34
    it('still registers every route 1.0.15 calls', function () {
        $fixture = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/client-contract/1.0.15-routes.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($fixture['routes'])->toBeArray()->not->toBeEmpty();

        $routes = Route::getRoutes();

        foreach ($fixture['routes'] as ['method' => $method, 'uri' => $uri]) {
            $path = '/'.preg_replace('/\{[^}]+\}/', '1', $uri);

            try {
                $matched = $routes->match(Request::create($path, $method));
            } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
                $this->fail("No route matches {$method} {$uri}, which 1.0.15 calls.");
            }

            expect($matched->uri())->toBe($uri, "{$method} {$uri} is answered by {$matched->uri()} instead.");
        }
    });

    // Since 1.1.0–1.1.4, per route (`since` in the fixture). solyto/app src/lib/config/apiRoutes.ts and its call sites at each tag
    it('still registers every route 1.1.x calls', function () {
        $fixture = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/client-contract/1.1-routes.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($fixture['routes'])->toBeArray()->not->toBeEmpty();

        $routes = Route::getRoutes();

        foreach ($fixture['routes'] as ['since' => $since, 'method' => $method, 'uri' => $uri]) {
            $path = '/'.preg_replace('/\{[^}]+\}/', '1', $uri);

            try {
                $matched = $routes->match(Request::create($path, $method));
            } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
                $this->fail("No route matches {$method} {$uri}, which {$since} calls.");
            }

            expect($matched->uri())->toBe($uri, "{$method} {$uri} is answered by {$matched->uri()} instead.");
        }
    });

    // Since 1.0.15. solyto/app src/lib/state/Auth.svelte.ts:97-108, src/lib/types/auth.ts:44-49
    it('keeps the token and user keys on login', function () {
        $user = makeUser(['email' => 'contract@example.com', 'password' => Hash::make('password1234')]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'contract@example.com',
            'password' => 'password1234',
            'platform' => 'web',
        ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => [
                'token', 'token_type', 'token_expires_at',
                'user' => ['id', 'name', 'email', 'role'],
            ]]);
    });

    // Since 1.0.15. solyto/app src/lib/state/Auth.svelte.ts:122-135, src/lib/types/auth.ts:44-49
    it('keeps the token and user keys on refresh', function () {
        $user = makeUser();

        $this->withHeaders(authHeaders($user))
            ->postJson('/api/v1/auth/refresh')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => [
                'token', 'token_type', 'token_expires_at',
                'user' => ['id', 'name', 'email', 'role'],
            ]]);
    });

    // Since 1.0.15. solyto/app src/lib/state/Auth.svelte.ts:74-79, src/lib/types/auth.ts:9-36
    it('keeps the user, profile and settings keys on the current user', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/users/me')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonStructure(['data' => [
                'id', 'name', 'email', 'role', 'created_at',
                'profile' => ['profile_image_path'],
                'settings' => [
                    'language', 'timezone', 'date_format', 'time_format', 'navigation',
                    'first_visit', 'temperature_unit',
                ],
            ]]);
    });

    // Since 1.1.1. solyto/app src/lib/state/Auth.svelte.ts:87-92
    it('keeps account_deletion_requires_email in the current user meta', function () {
        $user = makeUser();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/users/me')
            ->assertStatus(200);

        expect($response->json('meta.account_deletion_requires_email'))->toBeBool();
    });

    // Since 1.1.2. solyto/app src/lib/state/Auth.svelte.ts:543, src/lib/types/auth.ts:20
    it('keeps week_start in the current user settings', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/users/me')
            ->assertStatus(200)
            ->assertJsonPath('data.settings.week_start', 'monday');
    });

    // Since 1.0.15. solyto/app src/lib/state/Notes.svelte.ts:52-62, src/lib/types/note.ts:3-12, src/lib/types/tag.ts:1-7
    it('keeps the note fields on the note list', function () {
        $user = makeUser();
        $category = NoteCategory::factory()->forUser($user)->create();
        $note = Note::factory()->forUser($user)->forCategory($category)->create();
        $note->tags()->attach(Tag::factory()->forUser($user)->create());

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notes')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $note->id)
            ->assertJsonPath('data.0.category_id', $category->id)
            ->assertJsonStructure(['data' => [[
                'id', 'title', 'content', 'category_id', 'is_favorite', 'created_at', 'updated_at',
                'tags' => [['id', 'name', 'color', 'created_at', 'updated_at']],
            ]]]);
    });

    // Since 1.0.15. solyto/app src/lib/state/Notes.svelte.ts:82-85, src/lib/types/note.ts:28-36
    it('keeps the note category fields including children', function () {
        $user = makeUser();
        $parent = NoteCategory::factory()->forUser($user)->withTitle('Parent')->create();
        NoteCategory::factory()->forUser($user)->withParent($parent)->withTitle('Child')->create();

        $category = ['id', 'title', 'parent_id', 'sort_order', 'created_at', 'updated_at', 'children'];

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notes/categories')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.title', 'Parent')
            ->assertJsonPath('data.0.children.0.title', 'Child')
            ->assertJsonPath('data.0.children.0.parent_id', $parent->id)
            ->assertJsonStructure(['data' => [[
                ...$category,
                'children' => [$category],
            ]]]);
    });

    // Since 1.0.15. solyto/app src/lib/state/Todos.svelte.ts:77-84, src/lib/types/todo.ts:22-45 (relevance is computed client-side), src/lib/types/todo.ts:118-124
    it('keeps the todo fields on the todo list', function () {
        $user = makeUser();
        $category = TodoCategory::factory()->forUser($user)->create();
        $todo = Todo::factory()->forUser($user)->forCategory($category)->create();
        $todo->tags()->attach(Tag::factory()->forUser($user)->create());
        TodoSubtask::factory()->forTodo($todo)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/todos')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $todo->id)
            ->assertJsonPath('data.0.category.id', $category->id)
            ->assertJsonStructure(['data' => [[
                'id', 'title', 'description', 'link', 'priority', 'status', 'effort', 'progress',
                'is_completed', 'due_at', 'recurrence_frequency', 'recurrence_interval',
                'recurrence_ends_at', 'parent_task_id', 'auto_generated', 'created_at',
                'updated_at', 'completed_at',
                'category' => ['id', 'title', 'created_at', 'updated_at'],
                'tags' => [['id', 'name', 'color', 'created_at', 'updated_at']],
                'subtasks' => [['id', 'title', 'is_completed', 'created_at', 'updated_at']],
            ]]]);
    });

    // Since 1.0.15. solyto/app src/lib/state/Todos.svelte.ts:104-110, src/lib/types/todo.ts:76-81
    it('keeps the todo category fields', function () {
        $user = makeUser();
        TodoCategory::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/todos/categories')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['*' => ['id', 'title', 'created_at', 'updated_at']]]);
    });

    // Since 1.0.15. solyto/app src/lib/state/Todos.svelte.ts:112-125, src/lib/types/todo.ts:101-108
    it('keeps the todo workspace fields', function () {
        $user = makeUser();
        $workspace = TodoWorkspace::factory()->forUser($user)->create();
        $workspace->categories()->attach(TodoCategory::factory()->forUser($user)->create());

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/todos/workspaces')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['*' => [
                'id', 'title', 'is_hideable', 'created_at', 'updated_at', 'categories',
            ]]]);

        $index = collect($response->json('data'))->search(fn (array $w) => $w['id'] === $workspace->id);
        expect($index)->toBeInt();
        $response->assertJsonStructure(['data' => [$index => [
            'categories' => [['id', 'title', 'created_at', 'updated_at']],
        ]]]);
    });

    // Since 1.0.15. solyto/app src/lib/state/Calendars.svelte.ts:89-93, src/lib/types/calendar.ts:3-14
    it('keeps the calendar fields on the calendar list', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/calendars', ['name' => 'Work'])
            ->assertStatus(201);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['*' => [
                'id', 'name', 'color', 'description', 'timezone', 'order',
                'is_shared', 'share_owner', 'invite_status', 'share_token',
            ]]]);
    });

    // Since 1.1.3. solyto/app src/lib/components/calendars/NavigationEntry.svelte:39,71, src/lib/components/calendars/CalendarEdit.svelte:83,111, src/lib/components/calendars/DeleteModal.svelte:10,14
    it('flags external calendars on the calendar list', function () {
        $user = makeUser();
        externalCalendar($user, (new FakeCalDavServer())->fake());

        $calendars = collect($this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars')
            ->assertStatus(200)
            ->json('data'))->keyBy('name');

        expect($calendars['Remote']['is_external'])->toBeTrue()
            ->and($calendars['My Calendar']['is_external'])->toBeFalse();
    });

    // Since 1.0.15. solyto/app src/lib/state/Calendars.svelte.ts:111-150, src/lib/types/calendar.ts:28-47 (created_at/updated_at are never read)
    it('keeps the event fields on the month event list', function () {
        $user = makeUser();
        $instanceId = $this->actingAs($user, 'sanctum')->getJson('/api/v1/calendars')->json('data.0.id');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/calendars/'.$instanceId.'/events', [
                'title' => 'Contract meeting',
                'start_date' => '2026-03-10 09:00:00',
                'end_date' => '2026-03-10 10:00:00',
                'is_all_day' => false,
                'calendar_id' => $instanceId,
            ])
            ->assertStatus(201);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars/events/2026-03')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.title', 'Contract meeting')
            ->assertJsonStructure(['data' => [[
                'id', 'uri', 'title', 'description', 'location', 'start_date', 'end_date',
                'is_all_day', 'is_recurring', 'recurrence_rule', 'recurrence_end',
                'original_start_date', 'calendar_id', 'calendar_name', 'calendar_color', 'etag',
            ]]]);
    });

    // Since 1.1.3. solyto/app src/lib/state/Calendars.svelte.ts:147-148 (a missing key reads as an empty list)
    it('lists unavailable and rejected external calendars in the month event meta', function () {
        $remote = (new FakeCalDavServer())->fake();
        $remote->credentials = ['alice', 'changed-on-the-server'];
        $user = makeUser();
        $calendar = externalCalendar($user, $remote);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars/events/'.now()->format('Y-m'))
            ->assertStatus(200);

        expect($response->json('meta.unavailable_calendars'))->toBe([$calendar->instanceId])
            ->and($response->json('meta.credentials_rejected_calendars'))->toBe([$calendar->instanceId]);
    });

    // Since 1.0.15. solyto/app src/lib/state/Contacts.svelte.ts:142-145, src/lib/types/contact.ts:1-7
    it('keeps the address book fields on the address book list', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books', ['name' => 'Personal'])
            ->assertStatus(201);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/address-books')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['*' => ['id', 'name', 'uri', 'color', 'description']]]);
    });

    // Since 1.0.15. solyto/app src/lib/state/Contacts.svelte.ts:147-150, src/lib/types/contact.ts:19-42 (title has never been returned)
    it('keeps the contact fields on the contact list', function () {
        $user = makeUser();
        $addressBookId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books', ['name' => 'Work'])
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books/'.$addressBookId.'/contacts', [
                'address_book_id' => $addressBookId,
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => json_encode([['value' => 'john@example.com', 'type' => 'INTERNET']]),
                'phone' => json_encode([['value' => '+49 30 123456', 'type' => 'CELL']]),
            ])
            ->assertStatus(201);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/address-books/contacts')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.first_name', 'John')
            ->assertJsonPath('data.0.address_book_id', $addressBookId)
            ->assertJsonStructure(['data' => [[
                'uid', 'uri', 'full_name', 'first_name', 'last_name', 'middle_name', 'prefix',
                'suffix', 'groups', 'organization', 'note', 'street', 'city', 'state',
                'postal_code', 'country', 'etag', 'address_book_id', 'address_book_color',
                'email' => [['value', 'type']],
                'phone' => [['value', 'type']],
            ]]]);
    });

    // Since 1.1.4. solyto/app src/lib/state/Contacts.svelte.ts:128-129, src/lib/types/contact.ts:42 (a missing has_photo only costs extra photo requests)
    it('keeps has_photo on the contact list', function () {
        $user = makeUser();
        $addressBookId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books', ['name' => 'Work'])
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books/'.$addressBookId.'/contacts', [
                'address_book_id' => $addressBookId,
                'first_name' => 'John',
                'last_name' => 'Doe',
            ])
            ->assertStatus(201);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/address-books/contacts')
            ->assertStatus(200)
            ->assertJsonPath('data.0.has_photo', false);
    });

    // Since 1.0.15. solyto/app src/lib/state/TimeTracking.svelte.ts:48-51, src/lib/types/time_tracking.ts:1-17 (total_duration is never read)
    it('keeps the time tracking project fields on the project list', function () {
        $user = makeUser();
        $project = TimeTrackingProject::factory()->forUser($user)->create();
        $project->categories()->attach(TimeTrackingCategory::factory()->forUser($user)->withColor('#AABBCC')->create());

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/time-tracking/projects')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $project->id)
            ->assertJsonStructure(['data' => [[
                'id', 'title', 'description', 'created_at', 'updated_at',
                'categories' => [['id', 'title', 'color', 'created_at', 'updated_at']],
            ]]]);
    });

    // Since 1.0.15. solyto/app src/lib/state/TimeTracking.svelte.ts:53-59, src/lib/components/time-tracking/Entry.svelte:35-59, src/lib/types/time_tracking.ts:19-29
    it('keeps the time tracking entry fields on the entry list', function () {
        $user = makeUser();
        $project = TimeTrackingProject::factory()->forUser($user)->create();
        $entry = TimeTrackingEntry::factory()->forUser($user)->forProject($project)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/time-tracking/entries')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $entry->id)
            ->assertJsonPath('data.0.project.id', $project->id)
            ->assertJsonStructure(['data' => [[
                'id', 'description', 'started_at', 'stopped_at', 'duration_minutes',
                'has_exact_times', 'created_at', 'updated_at',
                'project' => ['id', 'title'],
            ]]]);
    });

    // Since 1.0.15. solyto/app src/lib/state/TimeTracking.svelte.ts:147-152, src/lib/components/time-tracking/StatisticsView.svelte:31-58, src/lib/types/time_tracking.ts:75-87
    it('keeps the time tracking statistics fields', function () {
        $user = makeUser();
        $project = TimeTrackingProject::factory()->forUser($user)->create(['color' => null]);
        TimeTrackingEntry::factory()->forUser($user)->forProject($project)->create([
            'started_at' => '2026-03-10 09:00:00',
            'stopped_at' => '2026-03-10 10:00:00',
            'duration_minutes' => 60,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/time-tracking/entries/statistics?from=2026-03-01&to=2026-03-31')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.by_project.0.project_id', $project->id)
            ->assertJsonPath('data.by_project.0.total_minutes', 60)
            ->assertJsonPath('data.by_project.0.color', null)
            ->assertJsonStructure(['data' => [
                'total_minutes',
                'by_project' => [['project_id', 'project_title', 'color', 'total_minutes']],
            ]]);
    });

    // Since 1.0.15. solyto/app src/lib/state/BookLibrary.svelte.ts:68-72, src/lib/types/library_book.ts:10-32 (genre timestamps are never read)
    it('keeps the book fields on the book list', function () {
        $user = makeUser();
        $book = LibraryBook::factory()->forUser($user)->create();
        $book->genres()->attach(LibraryBookGenre::factory()->forUser($user)->create());
        $book->tags()->attach(Tag::factory()->forUser($user)->create());

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/books')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $book->id)
            ->assertJsonStructure(['data' => [[
                'id', 'title', 'author', 'series', 'volume', 'pages', 'current_page', 'lent_to',
                'is_where', 'cover', 'link', 'rating', 'publication_year', 'wishlist', 'summary',
                'started_at', 'finished_at', 'created_at', 'updated_at',
                'genres' => [['id', 'title']],
                'tags' => [['id', 'name', 'color']],
            ]]]);
    });

    // Since 1.0.15. solyto/app src/lib/state/MusicLibrary.svelte.ts:70-74, src/lib/types/library_music.ts:8-25 (genre timestamps are never read)
    it('keeps the music fields on the music list', function () {
        $user = makeUser();
        $music = LibraryMusic::factory()->forUser($user)->create();
        $music->genres()->attach(LibraryMusicGenre::factory()->forUser($user)->create());

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/music')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $music->id)
            ->assertJsonStructure(['data' => [[
                'id', 'title', 'artist', 'type', 'format', 'condition', 'rating', 'acquired_where',
                'additional_info', 'publication_year', 'cover', 'link', 'wishlist', 'created_at',
                'updated_at',
                'genres' => [['id', 'title']],
            ]]]);
    });

    // Since 1.0.15. solyto/app src/lib/state/MovieLibrary.svelte.ts:59-63, src/lib/types/library_movie.ts:10-25 (genre timestamps are never read)
    it('keeps the movie fields on the movie list', function () {
        $user = makeUser();
        $movie = LibraryMovie::factory()->forUser($user)->create();
        $movie->genres()->attach(LibraryMovieGenre::factory()->forUser($user)->create());
        $movie->tags()->attach(Tag::factory()->forUser($user)->create());

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/movies')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $movie->id)
            ->assertJsonStructure(['data' => [[
                'id', 'title', 'category', 'cover', 'link', 'rating', 'publication_year', 'wishlist',
                'started_at', 'finished_at', 'created_at', 'updated_at',
                'genres' => [['id', 'title']],
                'tags' => [['id', 'name', 'color']],
            ]]]);
    });

    // Since 1.0.15. solyto/app src/lib/state/GameLibrary.svelte.ts:55-59, src/lib/types/library_game.ts:10-29 (genre timestamps are never read)
    it('keeps the game fields on the game list', function () {
        $user = makeUser();
        $game = LibraryGame::factory()->forUser($user)->create();
        $game->genres()->attach(LibraryGameGenre::factory()->forUser($user)->create());
        $game->tags()->attach(Tag::factory()->forUser($user)->create());

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/games')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $game->id)
            ->assertJsonStructure(['data' => [[
                'id', 'title', 'platform', 'developer', 'publisher', 'publication_year',
                'playtime_hours', 'completed', 'cover', 'link', 'rating', 'wishlist', 'started_at',
                'finished_at', 'created_at', 'updated_at',
                'genres' => [['id', 'title']],
                'tags' => [['id', 'name', 'color']],
            ]]]);
    });

    // Since 1.0.15. solyto/app src/lib/components/settings/notifications/ManageNotificationTypes.svelte:50-55, src/lib/types/notification_settings.ts:1-38
    it('keeps every notification type and channel on the notification settings', function () {
        $user = makeUser();

        $keys = [];
        foreach (['music_release', 'book_release', 'friend_request', 'daily_day_reminder', 'daily_check_in_reminder', 'calendar_share', 'dev_request_comment', 'export_ready', 'movie_release'] as $type) {
            foreach (['ui', 'email', 'push', 'telegram'] as $channel) {
                $keys[] = "{$type}_{$channel}";
            }
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications/settings')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => $keys]);
    });

    // Since 1.0.15. solyto/app src/lib/state/CheckInData.svelte.ts:46-62
    it('keeps the check-in settings keys', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/users/me/settings/check-in')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['enabled_trackers', 'selected_sports']]);
    });

    // Since 1.0.15 (job river). The frontend line was not verified: solyto/app was not
    // available to the job. `value` has been in BudgetResource since the initial commit.
    it('keeps the budget value as a number on list and show', function () {
        $user = makeUser();
        $budget = Budget::factory()->forUser($user)->withValue(1250.5)->create();
        BudgetValue::factory()->forBudget($budget)->withValue(1250.5)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/finances/budget')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'title', 'type', 'value']]])
            ->assertJsonPath('data.0.value', 1250.5);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/finances/budget/'.$budget->id)
            ->assertStatus(200)
            ->assertJsonPath('data.value', 1250.5);
    });

    // Since 1.0.15 (job river). The frontend line was not verified: solyto/app was not
    // available to the job. `values` and `currentValue` have been in WealthFieldResource
    // since the initial commit.
    it('keeps currentValue and values on the wealth fields', function () {
        $user = makeUser();
        $field = WealthField::factory()->forUser($user)->create();
        WealthValue::factory()->forField($field)->historical(10)->withValue(100)->create();
        WealthValue::factory()->forField($field)->today()->withValue(200)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/finances/wealth/fields')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => [[
                'id',
                'title',
                'values' => [['id', 'date', 'value']],
                'currentValue' => ['id', 'date', 'value'],
            ]]])
            ->assertJsonPath('data.0.currentValue.value', 200);
    });

    // Since 1.0.15 (job river). `PUT finances/wealth/fields/{field}/value` is in
    // 1.0.15-routes.json; the request body 1.0.15 sends was not verified.
    it('accepts a wealth value without a date', function () {
        $user = makeUser();
        $field = WealthField::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/wealth/fields/'.$field->id.'/value', ['value' => 321])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.value', 321);
    });
    // Since 1.0.0 (confirmed by the frontend in room pdf-clipboards, 2026-11-16): any entry that is not an image with a
    // file_path is rendered as text and truncates content, which crashes the whole history if content is null.
    it('keeps file_path on images and leaves file entries out of the plain clipboard list', function () {
        $user = makeUser();
        $image = Clipboard::factory()->forUser($user)->create(['type' => 'image', 'content' => '', 'file_path' => $user->id.'/clipboard/a.png']);
        Clipboard::factory()->forUser($user)->create(['type' => 'file', 'content' => '', 'file_path' => $user->id.'/clipboard/b.pdf']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/clipboard')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $image->id)
            ->assertJsonPath('data.0.type', 'image')
            ->assertJsonPath('data.0.file_path', $user->id.'/clipboard/a.png')
            ->assertJsonStructure(['data' => [['id', 'content', 'type', 'file_path', 'created_at', 'updated_at']]]);
    });

    // Since 1.1.2. solyto/app src/lib/state/DevRequests.svelte.ts:27, src/lib/components/dev-requests/DevRequestEntry.svelte:61-63,87-89
    it('keeps fixed_in_version on the dev request list', function () {
        $user = makeUser();
        DevRequest::factory()->completed()->create(['fixed_in_version' => '1.1.2']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dev-requests')
            ->assertStatus(200)
            ->assertJsonPath('data.0.status', 'completed')
            ->assertJsonPath('data.0.fixed_in_version', '1.1.2');
    });

    // Since 1.0.15. solyto/app src/lib/components/settings/notifications/ManagePushNotifications.svelte:20-21
    it('keeps public_key on the vapid key', function () {
        $user = makeUser();
        config(['webpush.vapid.public_key' => 'test-public-key']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications/push/vapid-key')
            ->assertStatus(200)
            ->assertJsonPath('data.public_key', 'test-public-key');
    });

    // Since 1.0.15. solyto/app src/lib/state/Feeds.svelte.ts:58,103,116, src/lib/components/feeds/EntryEdit.svelte:26-36, src/lib/types/feed.ts:1-10
    it('keeps the feed subscription fields with comma-separated keyword strings', function () {
        $user = makeUser();
        FeedSubscription::factory()->forUser($user)->withWhitelist(['php', 'laravel'])->withBlacklist(['ads'])->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/subscriptions')
            ->assertStatus(200)
            ->assertJsonPath('data.0.whitelist', 'php,laravel')
            ->assertJsonPath('data.0.blacklist', 'ads')
            ->assertJsonStructure(['data' => [['id', 'feed_id', 'title', 'url', 'whitelist', 'blacklist']]]);
    });

    // Since 1.1.0. solyto/app src/lib/state/Integrations.svelte.ts:48-51, src/lib/types/integration.ts:1-9
    it('keeps every integration flag as a boolean', function () {
        $response = $this->actingAs(makeUser(), 'sanctum')
            ->getJson('/api/v1/integrations')
            ->assertStatus(200);

        foreach (['hardcover', 'tmdb', 'discogs', 'bgg', 'spotify', 'telegram', 'ntfy'] as $integration) {
            expect($response->json("data.{$integration}"))->toBeBool();
        }
    });

    // Since 1.1.0. solyto/app src/lib/state/Plugins.svelte.ts:32,37, src/lib/components/settings/AiPluginSettings.svelte:46,99,105, src/lib/types/plugin.ts:7-13
    it('keeps the user plugin fields', function () {
        $this->app->instance(AiModelSnapshot::class, new AiModelSnapshot(base_path('tests/Fixtures/ai-models.json')));

        $this->actingAs(makeUser(), 'sanctum')
            ->getJson('/api/v1/users/me/plugins')
            ->assertStatus(200)
            ->assertJsonPath('data.0.plugin', 'ai')
            ->assertJsonStructure(['data' => [['plugin', 'enabled', 'has_api_key', 'settings', 'model_available']]]);
    });

    // Since 1.1.0. solyto/app src/lib/state/Plugins.svelte.ts:33,38, src/lib/components/settings/AiPluginSettings.svelte:35,40, src/lib/types/plugin.ts:14-22
    it('keeps the AI provider fields with their models', function () {
        $this->app->instance(AiModelSnapshot::class, new AiModelSnapshot(base_path('tests/Fixtures/ai-models.json')));

        $this->actingAs(makeUser(), 'sanctum')
            ->getJson('/api/v1/plugins/ai/providers')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => [['provider', 'name', 'models' => [['id', 'name']]]]]);
    });

    // Since 1.1.0. solyto/app src/lib/state/Calendars.svelte.ts:169-175
    it('returns journal and check-in marks as a plain list of dates', function () {
        $user = makeUser();
        $journal = NoteCategory::where('user_id', $user->id)->where('title', NoteCategory::JOURNAL_TITLE)->first();
        Note::factory()->forUser($user)->forCategory($journal)->withTitle('2026-10-06')->create();
        CheckIn::factory()->forUser($user)->create(['date' => '2026-10-08']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notes/journal/marks?from=2026-10-01&to=2026-10-31')
            ->assertStatus(200)
            ->assertJsonPath('data', ['2026-10-06']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/check-in/marks?from=2026-10-01&to=2026-10-31')
            ->assertStatus(200)
            ->assertJsonPath('data', ['2026-10-08']);
    });

    // Since 1.1.0. solyto/app src/lib/state/Notes.svelte.ts:101-112, src/lib/services/JournalService.ts:7-17, src/lib/types/note.ts:28-38
    it('lists the journal as a system category in the hidden category list', function () {
        $user = makeUser();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notes/categories/hidden')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'title', 'parent_id', 'sort_order', 'is_system', 'is_hidden', 'children']]]);

        expect(collect($response->json('data'))->where('is_system', true))->toHaveCount(1);
    });

    // Since 1.1.0. solyto/app src/lib/state/Notes.svelte.ts:68-75, src/lib/types/note.ts:3-12
    it('keeps the note fields on the hidden note list', function () {
        $user = makeUser();
        $journal = NoteCategory::where('user_id', $user->id)->where('title', NoteCategory::JOURNAL_TITLE)->first();
        $note = Note::factory()->forUser($user)->forCategory($journal)->withTitle('2026-10-06')->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notes/hidden')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $note->id)
            ->assertJsonPath('data.0.category_id', $journal->id)
            ->assertJsonStructure(['data' => [['id', 'title', 'content', 'category_id', 'tags', 'is_favorite', 'created_at', 'updated_at']]]);
    });

    // Since 1.1.0. solyto/app src/lib/components/settings/FeatureSettings.svelte:41,48,55, src/lib/components/notes/NoteNavigationDesktop.svelte:54
    it('keeps the journal and calendar mark flags in the current user settings', function () {
        $this->actingAs(makeUser(), 'sanctum')
            ->getJson('/api/v1/users/me')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['settings' => ['journal_enabled', 'journal_calendar_mark', 'check_in_calendar_mark']]]);
    });

    // Since 1.1.0. solyto/app src/lib/state/Finances.svelte.ts:99-104, src/lib/types/finance.ts:3-7
    it('keeps the budget value fields with a numeric value', function () {
        $user = makeUser();
        $budget = Budget::factory()->forUser($user)->create();
        BudgetValue::factory()->forBudget($budget)->withValue(1250.5)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/finances/budget/'.$budget->id.'/values')
            ->assertStatus(200)
            ->assertJsonPath('data.0.value', 1250.5)
            ->assertJsonStructure(['data' => [['id', 'date', 'value']]]);
    });

    // Since 1.1.0. solyto/app src/lib/state/Auth.svelte.ts:276-284, src/lib/components/settings/PasswordSettings.svelte:48-53
    it('answers a password change with success or field errors on current_password and new_password', function () {
        $user = makeUser(['password' => Hash::make('old-password-1234')]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/change-password', [
                'current_password' => 'wrong-password',
                'new_password' => 'New-password-5678!',
                'new_password_confirmation' => 'New-password-5678!',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('current_password');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/change-password', [
                'current_password' => 'old-password-1234',
                'new_password' => 'New-password-5678!',
                'new_password_confirmation' => 'something-else',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('new_password');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/change-password', [
                'current_password' => 'old-password-1234',
                'new_password' => 'New-password-5678!',
                'new_password_confirmation' => 'New-password-5678!',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    });

    // Since 1.1.0. solyto/app src/lib/services/ApiService.ts:124,161
    it('answers a client below the minimum version with 426 and min_client_version in meta', function () {
        config(['app.min_client_version' => '1.1.0']);

        $this->actingAs(makeUser(), 'sanctum')
            ->withHeaders(['X-Client-Version' => '1.0.15'])
            ->getJson('/api/v1/users/me')
            ->assertStatus(426)
            ->assertJsonPath('meta.min_client_version', '1.1.0');
    });

    // Since 1.1.1. solyto/app src/lib/components/settings/AccountDeletionSettings.svelte:35-40
    it('answers a deletion request with data.status or an error on password', function () {
        Mail::fake();
        $user = makeUser(['password' => Hash::make('secret-password')]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/users/me/deletion-request', ['password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/users/me/deletion-request', ['password' => 'secret-password'])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'email_sent');

        config(['auth.policy.account_deletion_confirmation' => false]);
        $other = makeUser(['password' => Hash::make('secret-password')]);

        $this->actingAs($other, 'sanctum')
            ->postJson('/api/v1/users/me/deletion-request', ['password' => 'secret-password'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'deleted');
    });

    // Since 1.1.1. solyto/app src/routes/auth/confirm-account-deletion/+page.svelte:39-42
    it('answers an unknown account deletion token with the invalid_token code', function () {
        Mail::fake();
        $user = makeUser(['password' => Hash::make('secret-password')]);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/users/me/deletion-request', ['password' => 'secret-password'])
            ->assertStatus(200);

        $this->postJson('/api/v1/auth/confirm-account-deletion', ['token' => 'not-the-token', 'email' => $user->email])
            ->assertStatus(401)
            ->assertJsonPath('errors', ['invalid_token']);
    });

    // Since 1.1.2. solyto/app src/lib/state/WidgetBridge.ts:58-69, src/lib/types/auth.ts:55-59
    it('keeps token and token_expires_at on the widget token', function () {
        $token = makeUser()->createToken('auth-token-mobile', expiresAt: now()->addDays(30))->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/widget-token')
            ->assertStatus(201);

        expect($response->json('data.token'))->toBeString()->not->toBeEmpty()
            ->and($response->json('data.token_expires_at'))->toBeString();
    });

    // Since 1.1.2. solyto/app src/lib/services/ApiService.ts:146-147,189-200
    it('answers a throttled request with 429, retry_after in meta and an error code', function () {
        $credentials = ['email' => 'throttled@example.com', 'password' => 'wrong-password'];

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/v1/auth/login', $credentials)->assertStatus(401);
        }

        $response = $this->postJson('/api/v1/auth/login', $credentials)->assertStatus(429);

        expect($response->json('meta.retry_after'))->toBeInt()->toBeGreaterThan(0)
            ->and($response->json('errors.0'))->toBeString();
    });

    // Since 1.1.3. solyto/app src/lib/state/ExternalCalendars.svelte.ts:44-56,83, src/lib/types/calendar.ts:183-195
    it('keeps the external account and calendar candidate fields', function () {
        $remote = (new FakeCalDavServer())->fake();
        $user = makeUser();

        $accountId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/calendars/external/accounts', [
                'server_url' => FakeCalDavServer::ORIGIN,
                'username' => 'alice',
                'password' => 'app-password',
            ])
            ->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'server_url', 'username', 'calendars']])
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/calendars/external/accounts/{$accountId}/available")
            ->assertStatus(200)
            ->assertJsonStructure(['data' => [['url', 'name', 'color', 'is_connected']]]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/calendars/external/accounts/{$accountId}/calendars", ['urls' => [$remote->url()]])
            ->assertStatus(201);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars/external/accounts')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'server_url', 'username', 'calendars' => [['id', 'remote_url']]]]]);
    });

    // Since 1.1.3. solyto/app src/lib/services/ExternalCalendarService.ts:9-14,21-23
    it('answers a failed external account connect with the code the app maps', function (string $case, string $code) {
        $remote = (new FakeCalDavServer())->fake();
        match ($case) {
            'credentials' => $remote->credentials = ['alice', 'other'],
            'unreachable' => $remote->down = true,
            default => null,
        };
        $serverUrl = match ($case) {
            'not allowed' => 'http://127.0.0.1:8080',
            'empty' => FakeCalDavServer::ORIGIN.'/nothing',
            default => FakeCalDavServer::ORIGIN,
        };

        $this->actingAs(makeUser(), 'sanctum')
            ->postJson('/api/v1/calendars/external/accounts', [
                'server_url' => $serverUrl,
                'username' => 'alice',
                'password' => 'app-password',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors', [$code]);
    })->with([
        'url not allowed' => ['not allowed', 'external_url_not_allowed'],
        'server unreachable' => ['unreachable', 'external_server_unreachable'],
        'no calendars' => ['empty', 'external_no_calendars'],
        'credentials rejected' => ['credentials', 'external_credentials_rejected'],
    ]);

    // Since 1.1.3. solyto/app src/lib/state/FeedImport.svelte.ts:49-66,103, src/lib/components/feeds/FeedImportModal.svelte:92-100, src/lib/types/feed.ts:63-75
    it('keeps the feed import state fields and answers an upload during an import with 409', function () {
        Queue::fake();
        $user = makeUser();
        $opml = '<?xml version="1.0" encoding="UTF-8"?><opml version="2.0"><body>'
            .'<outline text="Feed 1" xmlUrl="https://feed1.example/rss"/></body></opml>';
        $upload = fn () => $this->actingAs($user, 'sanctum')->post(
            '/api/v1/feeds/import',
            ['file' => UploadedFile::fake()->createWithContent('feeds.opml', $opml)],
            ['Accept' => 'application/json']
        );
        $stateFields = ['data' => ['stage', 'total', 'processed', 'feeds' => [['url', 'title', 'already_subscribed', 'status']]]];

        $upload()->assertStatus(200)->assertJsonPath('data.stage', 'parsed')->assertJsonStructure($stateFields);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/import/state')
            ->assertStatus(200)
            ->assertJsonStructure($stateFields);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/feeds/import/select', ['urls' => ['https://feed1.example/rss']])
            ->assertStatus(200);

        $upload()->assertStatus(409);
    });

    // Since 1.1.4. solyto/app src/lib/state/Clipboard.svelte.ts:23,73, src/lib/components/clipboard/ClipboardList.svelte:84,141
    it('keeps the file fields on the clipboard list with include_files', function () {
        $user = makeUser();
        Clipboard::factory()->forUser($user)->create([
            'type' => 'file', 'content' => '', 'file_path' => $user->id.'/clipboard/a.pdf',
            'file_name' => 'a.pdf', 'file_size' => 1024, 'mime_type' => 'application/pdf',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/clipboard?include_files=1')
            ->assertStatus(200)
            ->assertJsonPath('data.0.type', 'file')
            ->assertJsonPath('data.0.file_name', 'a.pdf')
            ->assertJsonPath('data.0.file_size', 1024)
            ->assertJsonPath('data.0.mime_type', 'application/pdf');
    });

    // Since 1.1.4. solyto/app src/lib/components/dashboard/Inspiration.svelte:53-55, src/lib/components/dashboard/widgets/NewestNotesWidget.svelte:24-33
    it('keeps the note fields on the recent notes', function () {
        $user = makeUser();
        $note = Note::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notes/recent')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $note->id)
            ->assertJsonStructure(['data' => [['id', 'title', 'created_at', 'updated_at']]]);
    });

    // Since 1.1.4. solyto/app src/lib/state/Finances.svelte.ts:42, src/lib/services/BudgetIntervalService.ts:12-13 (a missing interval reads as monthly)
    it('keeps interval on the budget list', function () {
        $user = makeUser();
        Budget::factory()->forUser($user)->create(['interval' => 'quarterly']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/finances/budget')
            ->assertStatus(200)
            ->assertJsonPath('data.0.interval', 'quarterly');
    });
});
