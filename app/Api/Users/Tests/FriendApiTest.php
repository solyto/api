<?php

use App\Api\Users\Models\Friend;
use App\Api\Users\Models\FriendRequest;
use App\Api\Users\Notifications\FriendRequestNotification;
use App\Api\Users\Services\FriendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

describe('GET /api/v1/friends', function () {
    it('lists the authenticated users friends', function () {
        $user = makeUser();
        $friend = makeUser();
        Friend::factory()->forUsers($user, $friend)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/friends')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    });
});

describe('GET /api/v1/friends/requests', function () {
    it('lists received and sent friend requests', function () {
        $user = makeUser();
        $sender = makeUser();
        $receiver = makeUser();

        FriendRequest::factory()->forUsers($sender, $user)->create();
        FriendRequest::factory()->forUsers($user, $receiver)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/friends/requests')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    });
});

describe('POST /api/v1/friends/requests', function () {
    it('sends a friend request and notifies the receiver', function () {
        Notification::fake();
        $sender = makeUser();
        $receiver = makeUser();

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/v1/friends/requests', [
                'receiver_id' => $receiver->id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        expect(FriendRequest::where('sender_id', $sender->id)->where('receiver_id', $receiver->id)->exists())->toBeTrue();

        Notification::assertSentTo($receiver, FriendRequestNotification::class);
    });

    it('rejects an unknown receiver', function () {
        $sender = makeUser();

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/v1/friends/requests', [
                'receiver_id' => '00000000-0000-0000-0000-000000000000',
            ])
            ->assertStatus(422);
    });
});

describe('PUT /api/v1/friends/requests/{request}/accept', function () {
    it('lets the receiver accept a friend request', function () {
        $sender = makeUser();
        $receiver = makeUser();
        $request = FriendRequest::factory()->forUsers($sender, $receiver)->create();

        $this->actingAs($receiver, 'sanctum')
            ->putJson('/api/v1/friends/requests/'.$request->id.'/accept')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        expect($request->fresh()->status)->toBe('accepted');
        expect(Friend::where('user_id_1', $sender->id)->where('user_id_2', $receiver->id)->exists())->toBeTrue();
    });

    it('forbids the sender from accepting', function () {
        $sender = makeUser();
        $receiver = makeUser();
        $request = FriendRequest::factory()->forUsers($sender, $receiver)->create();

        $this->actingAs($sender, 'sanctum')
            ->putJson('/api/v1/friends/requests/'.$request->id.'/accept')
            ->assertStatus(403);
    });
});

describe('PUT /api/v1/friends/requests/{request}/reject', function () {
    it('lets the receiver reject a friend request', function () {
        $sender = makeUser();
        $receiver = makeUser();
        $request = FriendRequest::factory()->forUsers($sender, $receiver)->create();

        $this->actingAs($receiver, 'sanctum')
            ->putJson('/api/v1/friends/requests/'.$request->id.'/reject')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        expect($request->fresh()->status)->toBe('rejected');
        expect(Friend::count())->toBe(0);
    });

    it('forbids the sender from rejecting', function () {
        $sender = makeUser();
        $receiver = makeUser();
        $request = FriendRequest::factory()->forUsers($sender, $receiver)->create();

        $this->actingAs($sender, 'sanctum')
            ->putJson('/api/v1/friends/requests/'.$request->id.'/reject')
            ->assertStatus(403);
    });
});

describe('FriendService', function () {
    it('sendFriendRequest attaches the sender and notifies the receiver', function () {
        Notification::fake();
        $sender = makeUser();
        $receiver = makeUser();

        $request = app(FriendService::class)->sendFriendRequest($sender, ['receiver_id' => $receiver->id]);

        expect($request->sender_id)->toBe($sender->id);
        expect($request->receiver_id)->toBe($receiver->id);
        expect($request->fresh()->status)->toBe('pending');
        Notification::assertSentTo($receiver, FriendRequestNotification::class);
    });

    it('acceptFriendRequest creates the friendship', function () {
        $sender = makeUser();
        $receiver = makeUser();
        $request = FriendRequest::factory()->forUsers($sender, $receiver)->create();

        $friend = app(FriendService::class)->acceptFriendRequest($request);

        expect($friend->user_id_1)->toBe($sender->id);
        expect($friend->user_id_2)->toBe($receiver->id);
        expect($request->fresh()->status)->toBe('accepted');
    });

    it('rejectFriendRequest marks the request as rejected', function () {
        $sender = makeUser();
        $receiver = makeUser();
        $request = FriendRequest::factory()->forUsers($sender, $receiver)->create();

        app(FriendService::class)->rejectFriendRequest($request);

        expect($request->fresh()->status)->toBe('rejected');
    });
});
