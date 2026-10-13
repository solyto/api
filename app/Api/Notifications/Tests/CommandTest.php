<?php

use Illuminate\Notifications\DatabaseNotification;

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
