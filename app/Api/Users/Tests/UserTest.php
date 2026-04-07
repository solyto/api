<?php

use App\Api\Users\Models\Friend;
use App\Api\Users\Models\FriendRequest;
use App\Api\Users\Models\User;
use App\Api\Users\Models\UserNotificationSettings;
use App\Api\Users\Models\UserProfile;
use App\Api\Users\Models\UserSettings;
use App\Api\Users\Models\VerificationToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('User Factory', function () {
    it('creates a valid user', function () {
        $user = User::factory()->create();

        expect($user->name)->toBeString();
        expect($user->email)->toBeEmail();
        expect($user->role)->toBeString();
        expect($user->password)->not->toBeEmpty();
    });

    it('creates a verified user', function () {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        expect($user->email_verified_at)->not->toBeNull();
    });

    it('creates an unverified user', function () {
        $user = User::factory()->unverified()->create();

        expect($user->email_verified_at)->toBeNull();
    });

    it('creates a user with custom attributes', function () {
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'role' => 'admin',
        ]);

        expect($user->name)->toBe('John Doe');
        expect($user->email)->toBe('john@example.com');
        expect($user->role)->toBe('admin');
    });
});

describe('User Model', function () {
    it('has correct fillable attributes', function () {
        $user = new User;

        expect($user->getFillable())->toBe([
            'name', 'email', 'password', 'role', 'language', 'email_verified_at',
        ]);
    });

    it('casts attributes correctly', function () {
        $user = User::factory()->create();

        expect($user->password)->toBeString();
    });

    it('has relationships', function () {
        $user = User::factory()->create();

        $profile = UserProfile::factory()->forUser($user)->create();
        $settings = UserSettings::factory()->forUser($user)->create();
        $notificationSettings = UserNotificationSettings::factory()->forUser($user)->create();

        expect($user->profile)->not()->toBeNull();
        expect($user->settings)->not()->toBeNull();
        expect($user->notificationSettings)->not()->toBeNull();
    });

    it('identifies admin users correctly', function () {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        expect($admin->isAdmin())->toBeTrue();
        expect($user->isAdmin())->toBeFalse();
    });

    it('identifies super admin users correctly', function () {
        $superAdmin = User::factory()->superAdmin()->create();
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        expect($superAdmin->isSuperAdmin())->toBeTrue();
        expect($admin->isSuperAdmin())->toBeFalse();
        expect($user->isSuperAdmin())->toBeFalse();
    });

    it('returns friends correctly', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Friend::factory()->forUsers($user1, $user2)->create();

        $friends = $user1->friends();

        expect($friends)->toHaveCount(1);
        expect($friends->first()->id)->toBe($user2->id);
    });

    it('returns sent friend requests correctly', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        FriendRequest::factory()->forUsers($user1, $user2)->create();

        $requests = $user1->sentFriendRequests;

        expect($requests)->toHaveCount(1);
    });

    it('returns received friend requests correctly', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        FriendRequest::factory()->forUsers($user1, $user2)->create();

        $requests = $user2->receivedFriendRequests;

        expect($requests)->toHaveCount(1);
    });

    it('returns all friend requests correctly', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        FriendRequest::factory()->forUsers($user1, $user2)->create();
        FriendRequest::factory()->forUsers($user1, $user3)->create();

        $requests = $user1->allFriendRequests();

        expect($requests)->toHaveCount(2);
    });
});

describe('UserProfile Factory', function () {
    it('creates a valid profile', function () {
        $user = User::factory()->create();
        $profile = UserProfile::factory()->forUser($user)->create();

        expect($profile->user_id)->toBe($user->id);
    });

    it('creates a profile with image', function () {
        $profile = UserProfile::factory()->withProfileImage()->create();

        expect($profile->profile_image_path)->not()->toBeNull();
    });
});

describe('UserSettings Factory', function () {
    it('creates valid settings', function () {
        $user = User::factory()->create();
        $settings = UserSettings::factory()->forUser($user)->create();

        expect($settings->user_id)->toBe($user->id);
    });

    it('creates settings with AI enabled', function () {
        $settings = UserSettings::factory()->withAiEnabled()->create();

        expect($settings->ai_enabled)->toBeTrue();
        expect($settings->openai_api_key)->not()->toBeNull();
    });

    it('creates settings with weather location', function () {
        $settings = UserSettings::factory()->withWeatherLocation()->create();

        expect($settings->weather_city)->not()->toBeNull();
        expect($settings->weather_latitude)->not()->toBeNull();
        expect($settings->weather_longitude)->not()->toBeNull();
    });
});

describe('UserNotificationSettings Factory', function () {
    it('creates valid notification settings', function () {
        $user = User::factory()->create();
        $settings = UserNotificationSettings::factory()->forUser($user)->create();

        expect($settings->user_id)->toBe($user->id);
        expect($settings->music_release_ui)->toBeBoolean();
        expect($settings->book_release_ui)->toBeBoolean();
    });

    it('creates telegram-only notification settings', function () {
        $settings = UserNotificationSettings::factory()->telegramOnly()->create();

        expect($settings->music_release_telegram)->toBeTrue();
        expect($settings->music_release_ui)->toBeFalse();
        expect($settings->music_release_push)->toBeFalse();
    });

    it('creates email-only notification settings', function () {
        $settings = UserNotificationSettings::factory()->emailOnly()->create();

        expect($settings->friend_request_email)->toBeTrue();
        expect($settings->friend_request_ui)->toBeFalse();
        expect($settings->friend_request_push)->toBeFalse();
    });
});

describe('Friend Factory', function () {
    it('creates a valid friendship', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $friend = Friend::factory()->forUsers($user1, $user2)->create();

        expect($friend->user_id_1)->toBe($user1->id);
        expect($friend->user_id_2)->toBe($user2->id);
        expect($friend->friends_since)->not()->toBeNull();
    });

    it('creates a recent friendship', function () {
        $friend = Friend::factory()->recent()->create();

        expect($friend->friends_since)->greaterThan(now()->subDays(30));
    });

    it('creates a long-term friendship', function () {
        $friend = Friend::factory()->longTerm()->create();

        expect($friend->friends_since)->lessThan(now()->subYears(2));
    });
});

describe('FriendRequest Factory', function () {
    it('creates a valid friend request', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $request = FriendRequest::factory()->forUsers($user1, $user2)->create();

        expect($request->sender_id)->toBe($user1->id);
        expect($request->receiver_id)->toBe($user2->id);
    });

    it('creates a pending friend request', function () {
        $request = FriendRequest::factory()->pending()->create();

        expect($request->status)->toBe('pending');
    });

    it('creates an accepted friend request', function () {
        $request = FriendRequest::factory()->accepted()->create();

        expect($request->status)->toBe('accepted');
    });

    it('creates a rejected friend request', function () {
        $request = FriendRequest::factory()->rejected()->create();

        expect($request->status)->toBe('rejected');
    });
});

describe('VerificationToken Factory', function () {
    it('creates a valid verification token', function () {
        $user = User::factory()->create();
        $token = VerificationToken::factory()->forUser($user)->create();

        expect($token->user_id)->toBe($user->id);
        expect($token->token)->not()->toBeEmpty();
        expect($token->expires_at)->not()->toBeNull();
    });

    it('creates an expired token', function () {
        $token = VerificationToken::factory()->expired()->create();

        expect($token->expires_at)->lessThan(now());
    });

    it('creates a token expiring soon', function () {
        $token = VerificationToken::factory()->expiresSoon()->create();

        expect($token->expires_at)->greaterThan(now())
            ->lessThan(now()->addHour());
    });

    it('creates a token expiring in 24 hours', function () {
        $token = VerificationToken::factory()->expiresInDay()->create();

        expect($token->expires_at)->greaterThan(now())
            ->lessThan(now()->addDay());
    });
});

describe('Friend Model', function () {
    it('belongs to both users', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $friend = Friend::factory()->forUsers($user1, $user2)->create();

        expect($friend->user1)->not()->toBeNull();
        expect($friend->user2)->not()->toBeNull();
    });
});

describe('FriendRequest Model', function () {
    it('belongs to sender and receiver', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $request = FriendRequest::factory()->forUsers($user1, $user2)->create();

        expect($request->sender)->not()->toBeNull();
        expect($request->receiver)->not()->toBeNull();
    });
});

describe('VerificationToken Model', function () {
    it('belongs to user', function () {
        $user = User::factory()->create();
        $token = VerificationToken::factory()->forUser($user)->create();

        expect($token->user)->not()->toBeNull();
    });
});
