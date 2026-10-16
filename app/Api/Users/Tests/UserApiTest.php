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

    it('forbids regular users from listing users', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/users')
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
