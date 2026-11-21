<?php

use App\Api\Users\Services\UserSettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

describe('GET /api/v1/users/me', function () {
    it('returns the authenticated user with profile and settings', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/users/me')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    });

    it('requires authentication', function () {
        $this->getJson('/api/v1/users/me')->assertStatus(401);
    });
});

describe('GET /api/v1/users', function () {
    it('allows admins to list users', function () {
        $admin = makeUser(['email' => 'admin@example.com', 'role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/users')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    });

    it('returns the requested page with pagination meta, newest first', function () {
        $admin = makeUser(['role' => 'admin', 'created_at' => now()->subYear()]);
        $users = collect(range(1, 4))->map(fn (int $i) => makeUser(['created_at' => now()->subDays($i)]));

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/users?per_page=2&page=2')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $users[2]->id)
            ->assertJsonPath('data.1.id', $users[3]->id)
            ->assertJsonPath('meta.pagination.current_page', 2)
            ->assertJsonPath('meta.pagination.last_page', 3)
            ->assertJsonPath('meta.pagination.per_page', 2)
            ->assertJsonPath('meta.pagination.total', 5)
            ->assertJsonPath('meta.pagination.from', 3)
            ->assertJsonPath('meta.pagination.to', 4)
            ->assertJsonPath('meta.pagination.has_more_pages', true);
    });

    it('keeps the legacy page size of 1000 when per_page is omitted', function () {
        $admin = makeUser(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/users')
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.per_page', 1000);
    });

    it('rejects an invalid page or per_page', function (string $query) {
        $admin = makeUser(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/users?'.$query)
            ->assertStatus(422);
    })->with(['per_page=0', 'per_page=101', 'per_page=abc', 'page=0']);

    it('searches name or email case-insensitively before paging', function () {
        $admin = makeUser(['role' => 'admin', 'name' => 'Admin', 'email' => 'admin@example.com']);
        $alice = makeUser(['name' => 'Alice', 'email' => 'first@example.com']);
        $bob = makeUser(['name' => 'Bob', 'email' => 'bob@alicorp.test']);
        makeUser(['name' => 'Carol', 'email' => 'carol@example.com']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/users?per_page=1&search=ALI')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.pagination.total', 2);

        $ids = collect($this->actingAs($admin, 'sanctum')->getJson('/api/v1/users?per_page=10&search=ALI')->json('data'))->pluck('id');
        expect($ids->sort()->values()->all())->toBe(collect([$alice->id, $bob->id])->sort()->values()->all());
    });

    it('matches LIKE wildcards in the search term literally', function () {
        $admin = makeUser(['role' => 'admin', 'name' => 'Admin', 'email' => 'admin@example.com']);
        $underscored = makeUser(['name' => 'a_b', 'email' => 'u1@example.com']);
        makeUser(['name' => 'axb', 'email' => 'u2@example.com']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/users?per_page=10&search=a_b')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $underscored->id);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/users?per_page=10&search=%25')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });

    it('returns empty data for a page beyond the last', function () {
        $admin = makeUser(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/users?per_page=10&page=5')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.pagination.total', 1);
    });

    it('forbids regular users from listing users', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/users')
            ->assertStatus(403);
    });
});

describe('GET /api/v1/users/growth', function () {
    beforeEach(function () {
        $this->travelTo(Carbon\CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));

        $this->admin = makeUser(['role' => 'admin', 'created_at' => '2025-06-02 10:00:00']);
        foreach (['2025-06-01 08:00:00', '2026-01-10 08:00:00', '2026-01-20 08:00:00', '2026-03-14 08:00:00'] as $createdAt) {
            makeUser(['created_at' => $createdAt]);
        }
    });

    it('returns cumulative totals per year and month, only periods with signups', function (string $groupBy, array $expected) {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/users/growth?group_by='.$groupBy)
            ->assertStatus(200)
            ->assertJsonPath('data', $expected);
    })->with([
        'year' => ['year', [['period' => '2025', 'total' => 2], ['period' => '2026', 'total' => 5]]],
        'month' => ['month', [['period' => '2025-06', 'total' => 2], ['period' => '2026-01', 'total' => 4], ['period' => '2026-03', 'total' => 5]]],
    ]);

    it('gap-fills the last 30 days, starting from the earlier total', function () {
        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/users/growth?group_by=day')
            ->assertStatus(200)
            ->json('data');

        expect($data)->toHaveCount(30)
            ->and($data[0])->toBe(['period' => '2026-02-14', 'total' => 4])
            ->and($data[27])->toBe(['period' => '2026-03-13', 'total' => 4])
            ->and($data[28])->toBe(['period' => '2026-03-14', 'total' => 5])
            ->and($data[29])->toBe(['period' => '2026-03-15', 'total' => 5]);
    });

    it('gap-fills the last 52 ISO weeks', function () {
        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/users/growth?group_by=week')
            ->assertStatus(200)
            ->json('data');

        expect($data)->toHaveCount(52)
            ->and($data[0])->toBe(['period' => '2025-W12', 'total' => 0])
            ->and($data[51])->toBe(['period' => '2026-W11', 'total' => 5]);
    });

    it('rejects an unknown group_by', function () {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/users/growth?group_by=hour')
            ->assertStatus(422);
    });

    it('forbids regular users', function () {
        $this->actingAs(makeUser(), 'sanctum')
            ->getJson('/api/v1/users/growth?group_by=year')
            ->assertStatus(403);
    });
});

describe('PUT /api/v1/users/{user}', function () {
    it('lets a user update their own profile', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/'.$user->id, [
                'name' => 'New Name',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'New Name');

        expect($user->fresh()->name)->toBe('New Name');
    });

    it('forbids a regular user from updating another user', function () {
        $user = makeUser();
        $other = makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/'.$other->id, ['name' => 'Hacked'])
            ->assertStatus(403);
    });

    it('lets an admin update another user', function () {
        $admin = makeUser(['role' => 'admin']);
        $other = makeUser();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/users/'.$other->id, ['name' => 'By Admin'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'By Admin');
    });
});

describe('GET /api/v1/users/{user}/public-profile', function () {
    it('returns the public profile', function () {
        $user = makeUser();
        $viewer = makeUser();

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/users/'.$user->id.'/public-profile')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $user->id);
    });
});

describe('PUT /api/v1/users/change-password', function () {
    it('changes the password and revokes tokens', function () {
        $user = makeUser(['password' => Hash::make('oldpassword123')]);
        $user->createToken('a');
        $user->createToken('b');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/change-password', [
                'current_password' => 'oldpassword123',
                'new_password' => 'newpassword123',
                'new_password_confirmation' => 'newpassword123',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        expect(Hash::check('newpassword123', $user->fresh()->password))->toBeTrue();
        expect($user->tokens()->count())->toBe(0);
    });

    it('rejects a wrong current password', function () {
        $user = makeUser(['password' => Hash::make('oldpassword123')]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/change-password', [
                'current_password' => 'wrong-password',
                'new_password' => 'newpassword123',
                'new_password_confirmation' => 'newpassword123',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');
    });

    it('rejects reusing the current password', function () {
        $user = makeUser(['password' => Hash::make('oldpassword123')]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/change-password', [
                'current_password' => 'oldpassword123',
                'new_password' => 'oldpassword123',
                'new_password_confirmation' => 'oldpassword123',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('new_password');
    });
});

describe('POST /api/v1/users/me/profile-image', function () {
    it('stores the profile image and dispatches the scaling job', function () {
        Queue::fake();
        Storage::fake('user_data');
        $user = makeUser();

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/v1/users/me/profile-image', [
                'profile_image' => UploadedFile::fake()->image('avatar.png', 100, 100),
            ]);

        $response->assertStatus(200)->assertJsonPath('success', true);

        expect($user->fresh()->profile->profile_image_path)->not->toBeNull();
        Storage::disk('user_data')->assertExists($user->fresh()->profile->profile_image_path);

        Queue::assertPushed(\App\Api\Users\Jobs\ScaleProfileImage::class);
    });

    it('returns an error without a file', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/users/me/profile-image', [])
            ->assertStatus(400);
    });
});

describe('User settings endpoints', function () {
    it('updates navigation', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/navigation', [
                'navigation' => json_encode(['dashboard' => true]),
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        expect($user->fresh()->settings->navigation)->toBe(json_encode(['dashboard' => true]));
    });

    it('returns stored nested navigation unchanged to clients from 1.1.0', function () {
        $nested = ['visible' => ['calendar' => false], 'desktop' => ['home', 'notes'], 'mobile' => null];
        $user = makeUser();
        $user->settings()->update(['navigation' => $nested]);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Client-Version', '1.1.0')
            ->getJson('/api/v1/users/me')
            ->assertStatus(200);

        expect(json_decode($response->json('data.settings.navigation'), true))->toBe($nested);
    });

    it('returns only the flat visibility to clients below 1.1.0 or without a version', function (?string $version) {
        $user = makeUser();
        $user->settings()->update(['navigation' => ['visible' => ['calendar' => false, 'notes' => true], 'desktop' => ['home'], 'mobile' => null]]);

        $request = $this->actingAs($user, 'sanctum');
        if ($version !== null) {
            $request = $request->withHeader('X-Client-Version', $version);
        }

        $response = $request->getJson('/api/v1/users/me')->assertStatus(200);

        expect(json_decode($response->json('data.settings.navigation'), true))->toBe(['calendar' => false, 'notes' => true]);
    })->with([null, '1.0.15']);

    it('returns flat navigation unchanged to older clients', function () {
        $user = makeUser();
        $user->settings()->update(['navigation' => ['calendar' => false]]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/users/me')->assertStatus(200);

        expect(json_decode($response->json('data.settings.navigation'), true))->toBe(['calendar' => false]);
    });

    it('keeps the stored orders when an older client saves flat navigation', function () {
        $user = makeUser();
        $user->settings()->update(['navigation' => ['visible' => ['calendar' => true], 'desktop' => ['home', 'notes'], 'mobile' => ['home']]]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/navigation', ['navigation' => json_encode(['calendar' => false, 'todos' => true])])
            ->assertStatus(200);

        expect(json_decode($user->fresh()->settings->navigation, true))->toBe([
            'visible' => ['calendar' => false, 'todos' => true],
            'desktop' => ['home', 'notes'],
            'mobile' => ['home'],
        ]);
    });

    it('stores nested navigation as sent', function () {
        $user = makeUser();
        $user->settings()->update(['navigation' => ['visible' => ['calendar' => true], 'desktop' => ['home', 'notes'], 'mobile' => null]]);
        $nested = ['visible' => ['calendar' => false], 'desktop' => null, 'mobile' => ['home', 'todos']];

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Client-Version', '1.1.0')
            ->putJson('/api/v1/users/me/settings/navigation', ['navigation' => json_encode($nested)])
            ->assertStatus(200);

        expect(json_decode($user->fresh()->settings->navigation, true))->toBe($nested);
    });

    it('updates language', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/language', ['language' => 'de'])
            ->assertStatus(200);

        expect($user->fresh()->settings->language)->toBe('de');
    });

    it('updates timezone', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/timezone', ['timezone' => 'Europe/Berlin'])
            ->assertStatus(200);

        expect($user->fresh()->settings->timezone)->toBe('Europe/Berlin');
    });

    it('accepts timezones only in the backward-compatible list', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/timezone', ['timezone' => 'Etc/GMT+1'])
            ->assertStatus(200);

        expect($user->fresh()->settings->timezone)->toBe('Etc/GMT+1');
    });

    it('rejects an unknown timezone', function (mixed $timezone) {
        $user = makeUser();
        $user->settings()->update(['timezone' => 'Europe/Berlin']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/timezone', ['timezone' => $timezone])
            ->assertStatus(422)
            ->assertJsonValidationErrors('timezone');

        expect($user->fresh()->settings->timezone)->toBe('Europe/Berlin');
    })->with([
        'unknown name' => ['Not/AZone'],
        'array' => [['Europe/Berlin']],
    ]);

    it('updates date format', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/date-format', ['date_format' => 'd.m.Y'])
            ->assertStatus(200);

        expect($user->fresh()->settings->date_format)->toBe('d.m.Y');
    });

    it('updates time format', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/time-format', ['time_format' => 'H:i'])
            ->assertStatus(200);

        expect($user->fresh()->settings->time_format)->toBe('H:i');
    });

    it('updates week start', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/week-start', ['week_start' => 'sunday'])
            ->assertStatus(200);

        expect($user->fresh()->settings->week_start)->toBe('sunday');
    });

    it('rejects an invalid week start', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/week-start', ['week_start' => 'saturday'])
            ->assertStatus(422);

        expect($user->fresh()->settings->week_start)->toBe('monday');
    });

    it('returns monday as the default week start', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/users/me')
            ->assertStatus(200)
            ->assertJsonPath('data.settings.week_start', 'monday');
    });

    it('updates weather city', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/weather-city', [
                'city' => 'Berlin',
                'latitude' => 52.52,
                'longitude' => 13.405,
            ])
            ->assertStatus(200);

        $settings = $user->fresh()->settings;
        expect($settings->weather_city)->toBe('Berlin');
        expect($settings->weather_latitude)->toBe(52.52);
        expect($settings->weather_longitude)->toBe(13.405);
    });

    it('updates weather temperature unit', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/weather-temperature-unit', ['temperature_unit' => 'f'])
            ->assertStatus(200);

        expect($user->fresh()->settings->temperature_unit)->toBe('f');
    });

    it('completes onboarding', function () {
        $user = makeUser();
        $user->settings()->update(['first_visit' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/complete-onboarding')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        expect($user->fresh()->settings->first_visit)->toBeFalse();
    });

    it('toggles the journal', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/journal', ['enabled' => true])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        expect($user->fresh()->settings->journal_enabled)->toBeTrue();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/journal', ['enabled' => false])
            ->assertStatus(200);

        expect($user->fresh()->settings->journal_enabled)->toBeFalse();
    });

    it('requires a boolean when updating the journal', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/journal', [])
            ->assertStatus(422);
    });

    it('toggles the journal calendar mark', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/journal-calendar-mark', ['enabled' => true])
            ->assertStatus(200);

        expect($user->fresh()->settings->journal_calendar_mark)->toBeTrue();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/journal-calendar-mark', ['enabled' => false])
            ->assertStatus(200);

        expect($user->fresh()->settings->journal_calendar_mark)->toBeFalse();
    });

    it('toggles the check-in calendar mark', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/check-in-calendar-mark', ['enabled' => true])
            ->assertStatus(200);

        expect($user->fresh()->settings->check_in_calendar_mark)->toBeTrue();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/check-in-calendar-mark', ['enabled' => false])
            ->assertStatus(200);

        expect($user->fresh()->settings->check_in_calendar_mark)->toBeFalse();
    });

    it('returns the journal and calendar mark settings off by default', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/users/me')
            ->assertStatus(200)
            ->assertJsonPath('data.settings.journal_enabled', false)
            ->assertJsonPath('data.settings.journal_calendar_mark', false)
            ->assertJsonPath('data.settings.check_in_calendar_mark', false);
    });

    it('shows check-in settings with defaults', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/users/me/settings/check-in')
            ->assertStatus(200)
            ->assertJsonPath('data.enabled_trackers.0', 'mood');
    });

    it('updates check-in settings', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/check-in', [
                'enabled_trackers' => ['mood', 'water'],
                'selected_sports' => ['dumbbell', 'bike', 'mountain', 'footprints', 'waves_ladder'],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.enabled_trackers', ['mood', 'water']);

        expect($user->fresh()->settings->check_in_settings)->toBe([
            'enabled_trackers' => ['mood', 'water'],
            'selected_sports' => ['dumbbell', 'bike', 'mountain', 'footprints', 'waves_ladder'],
        ]);
    });
});

describe('UserSettingsService', function () {
    it('updateWeatherCity persists all values', function () {
        $user = makeUser();

        app(UserSettingsService::class)->updateWeatherCity($user, [
            'city' => 'Paris',
            'latitude' => 48.85,
            'longitude' => 2.35,
        ]);

        $settings = $user->fresh()->settings;
        expect($settings->weather_city)->toBe('Paris');
        expect($settings->weather_latitude)->toBe(48.85);
        expect($settings->weather_longitude)->toBe(2.35);
    });

    it('updateCheckInSettings uses updateOrCreate', function () {
        $user = makeUser();

        $settings = app(UserSettingsService::class)->updateCheckInSettings($user, [
            'enabled_trackers' => ['mood'],
            'selected_sports' => ['dumbbell', 'bike', 'mountain', 'footprints', 'waves_ladder'],
        ]);

        expect($settings->user_id)->toBe($user->id);
        expect($settings->check_in_settings['enabled_trackers'])->toBe(['mood']);
    });
});
