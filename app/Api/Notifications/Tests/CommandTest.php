<?php

use Illuminate\Http\Client\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Http;

describe('Generate example notifications command', function () {
    it('generates 3 unread notifications of different types for the given email', function () {
        $user = makeUser(['name' => 'Virginia', 'email' => 'demo@example.com']);
        $other = makeUser(['name' => 'Wesley', 'email' => 'other@example.com']);

        $this->artisan('app:notifications:generate-examples', ['email' => 'demo@example.com'])
            ->expectsOutputToContain('Generated 3 example notifications for Virginia')
            ->assertSuccessful();

        expect(DatabaseNotification::count())->toBe(3)
            ->and(DatabaseNotification::whereNull('read_at')->count())->toBe(3)
            ->and($user->fresh()->notifications->pluck('type')->sort()->values()->all())
            ->toBe(['book_release', 'calendar_share', 'friend_request'])
            ->and($other->fresh()->notifications)->toBeEmpty();
    });

    it('generates notifications for the interactively selected user', function () {
        $user = makeUser(['name' => 'Virginia', 'email' => 'gin@example.com']);

        $this->artisan('app:notifications:generate-examples')
            ->expectsSearch('Search user (name, email or ID)', $user->id, 'gin', [$user->id => 'Virginia (gin@example.com)'])
            ->assertSuccessful();

        expect($user->fresh()->notifications)->toHaveCount(3);
    });

    it('shows an error for an unknown email', function () {
        $this->artisan('app:notifications:generate-examples', ['email' => 'missing@example.com'])
            ->expectsOutputToContain('User with email missing@example.com not found.')
            ->assertSuccessful();

        expect(DatabaseNotification::count())->toBe(0);
    });
});

describe('Test ntfy command', function () {
    beforeEach(function () {
        config([
            'services.ntfy.url' => 'https://ntfy.example.test',
            'services.ntfy.topic' => 'solyto-admin',
        ]);
        Http::fake();
    });

    it('publishes a test notification to the default topic', function () {
        $this->artisan('app:notifications:test-ntfy')
            ->expectsOutputToContain('Test notification published to topic solyto-admin.')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) =>
            $request->url() === 'https://ntfy.example.test'
            && $request['topic'] === 'solyto-admin'
            && $request['title'] === 'Test notification'
        );
    });

    it('publishes to the given topic', function () {
        $this->artisan('app:notifications:test-ntfy', ['topic' => 'solyto-traffic'])
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['topic'] === 'solyto-traffic');
    });

    it('fails without sending when ntfy is not configured', function () {
        config(['services.ntfy.url' => null]);

        $this->artisan('app:notifications:test-ntfy')
            ->expectsOutputToContain('ntfy is not configured')
            ->assertFailed();

        Http::assertNothingSent();
    });

    it('fails without sending when no topic is available', function () {
        config(['services.ntfy.topic' => null]);

        $this->artisan('app:notifications:test-ntfy')
            ->expectsOutputToContain('No topic given')
            ->assertFailed();

        Http::assertNothingSent();
    });
});
