<?php

use App\Api\Users\Models\Friend;
use App\Api\Users\Models\FriendRequest;
use App\Api\Users\Models\UserProfile;
use App\Api\Users\Notifications\FriendRequestNotification;
use App\Api\Users\Services\FriendService;
use Illuminate\Support\Facades\Notification;

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

describe('GET /api/v1/friends shape', function () {
    it('lists friends from both sides of the friendship with their profile image', function () {
        $user = makeUser();
        $added = makeUser(['name' => 'Added']);
        $adder = makeUser(['name' => 'Adder']);
        $stranger = makeUser();
        Friend::factory()->forUsers($user, $added)->create();
        Friend::factory()->forUsers($adder, $user)->create();
        Friend::factory()->forUsers($adder, $stranger)->create();
        UserProfile::updateOrCreate(['user_id' => $adder->id], ['profile_image_path' => 'user/adder.jpg']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/friends')
            ->assertStatus(200)
            ->assertJsonPath('data', [
                ['id' => $added->id, 'name' => 'Added', 'profile_image_path' => $added->profile?->profile_image_path],
                ['id' => $adder->id, 'name' => 'Adder', 'profile_image_path' => 'user/adder.jpg'],
            ]);
    });

    it('lists sent friend requests before received ones, each with the other user', function () {
        $this->travelTo('2026-11-20 09:30:00');
        $user = makeUser();
        $sender = makeUser(['name' => 'Sender']);
        $receiver = makeUser(['name' => 'Receiver']);
        $received = FriendRequest::factory()->forUsers($sender, $user)->pending()->create();
        $sent = FriendRequest::factory()->forUsers($user, $receiver)->rejected()->create();
        FriendRequest::factory()->forUsers($sender, $receiver)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/friends/requests')
            ->assertStatus(200)
            ->assertJsonPath('data', [
                [
                    'id' => $sent->id, 'status' => 'rejected', 'direction' => 'sent',
                    'user' => ['id' => $receiver->id, 'name' => 'Receiver', 'profile_image_path' => $receiver->profile?->profile_image_path],
                    'created_at' => '2026-11-20T09:30:00.000000Z',
                ],
                [
                    'id' => $received->id, 'status' => 'pending', 'direction' => 'received',
                    'user' => ['id' => $sender->id, 'name' => 'Sender', 'profile_image_path' => $sender->profile?->profile_image_path],
                    'created_at' => '2026-11-20T09:30:00.000000Z',
                ],
            ]);
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

        $response = $this->actingAs($sender, 'sanctum')
            ->postJson('/api/v1/friends/requests', [
                'receiver_id' => '00000000-0000-0000-0000-000000000000',
            ]);

        expectApiError($response, 422);
        $response->assertJsonValidationErrors('receiver_id');
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
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $sender->id)
            ->assertJsonPath('data.name', $sender->name);

        expect($request->fresh()->status)->toBe('accepted');
        expect(Friend::where('user_id_1', $sender->id)->where('user_id_2', $receiver->id)->exists())->toBeTrue();
    });

    it('forbids the sender from accepting', function () {
        $sender = makeUser();
        $receiver = makeUser();
        $request = FriendRequest::factory()->forUsers($sender, $receiver)->create();

        expectApiError($this->actingAs($sender, 'sanctum')
            ->putJson('/api/v1/friends/requests/'.$request->id.'/accept'), 403);
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

        expectApiError($this->actingAs($sender, 'sanctum')
            ->putJson('/api/v1/friends/requests/'.$request->id.'/reject'), 403);
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
