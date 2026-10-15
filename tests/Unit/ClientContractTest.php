<?php

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
});
