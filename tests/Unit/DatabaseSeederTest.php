<?php

use App\Api\Calendars\Services\CalendarService;
use App\Api\Contacts\Services\ContactService;
use App\Api\DevRequests\Models\DevRequest;
use App\Api\Todos\Models\Todo;
use App\Api\Users\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

covers(DatabaseSeeder::class);

function seededUser(string $email): User
{
    return User::where('email', $email)->sole();
}

it('creates the test accounts with data in the DAV and app databases', function () {
    $this->seed(DatabaseSeeder::class);

    $user = seededUser(DatabaseSeeder::TEST_EMAIL);
    $friend = seededUser(DatabaseSeeder::FRIEND_EMAIL);

    expect(Todo::forUser($user->id)->count())->toBeGreaterThan(0)
        ->and(collect(app(CalendarService::class)->list($user))->pluck('displayName')->all())
        ->toContain('My Calendar', 'Work', 'Shared with friend', 'Remote (fake)')
        ->and(collect(app(CalendarService::class)->list($friend))->pluck('displayName')->all())
        ->toContain('Shared with friend')
        ->and(app(ContactService::class)->listContacts($user))->toHaveCount(15);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/calendars/events/'.now($user->settings->timezone)->format('Y-m'))
        ->assertOk()
        ->assertJsonPath('success', true);
});

it('replaces the test accounts on every run instead of adding to them', function () {
    $this->seed(DatabaseSeeder::class);
    $firstId = seededUser(DatabaseSeeder::TEST_EMAIL)->id;
    $devRequests = DevRequest::count();

    $this->seed(DatabaseSeeder::class);

    expect(seededUser(DatabaseSeeder::TEST_EMAIL)->id)->not->toBe($firstId)
        ->and(User::whereIn('email', [DatabaseSeeder::TEST_EMAIL, DatabaseSeeder::FRIEND_EMAIL, DatabaseSeeder::PENDING_EMAIL])->count())->toBe(3)
        ->and(Todo::where('user_id', $firstId)->count())->toBe(0)
        ->and(DevRequest::count())->toBe($devRequests);
});

it('refuses to run in production', function () {
    $this->app['env'] = 'production';

    expect(fn () => app(DatabaseSeeder::class)->setContainer(app())->__invoke())
        ->toThrow(RuntimeException::class);

    expect(User::count())->toBe(0);
});
