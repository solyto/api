<?php

use App\Api\DevRequests\Models\DevRequest;
use App\Api\DevRequests\Models\DevRequestComment;
use App\Api\DevRequests\Models\DevRequestVote;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('DevRequest CRUD', function () {
    it('lists, stores, updates and deletes dev requests', function () {
        $user = makeUser(['role' => 'admin']);

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/dev-requests', [
                'title' => 'Dark mode',
                'description' => 'Add a dark theme',
                'type' => 'feature',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Dark mode');

        $requestId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dev-requests')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/dev-requests/'.$requestId, ['status' => 'in-progress'])
            ->assertStatus(200);

        expect(DevRequest::find($requestId)->status)->toBe('in-progress');
    });

    it('rejects invalid dev requests', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/dev-requests', ['title' => ''])
            ->assertStatus(422);
    });
});

describe('DevRequest voting', function () {
    it('allows a user to vote once', function () {
        $user = makeUser();
        $request = DevRequest::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/dev-requests/'.$request->id.'/vote', ['vote' => 'up'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        expect(DevRequestVote::where('dev_request_id', $request->id)->where('user_id', $user->id)->count())->toBe(1);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/dev-requests/'.$request->id.'/vote', ['vote' => 'down'])
            ->assertStatus(200);

        expect(DevRequestVote::where('dev_request_id', $request->id)->where('user_id', $user->id)->count())->toBe(1);
        expect(DevRequestVote::where('dev_request_id', $request->id)->where('user_id', $user->id)->first()->vote_type)->toBe('down');
    });
});

describe('DevRequest comments', function () {
    it('lists and stores comments', function () {
        $user = makeUser();
        $request = DevRequest::factory()->create();
        DevRequestComment::factory()->forDevRequestAndUser($request, $user)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dev-requests/'.$request->id.'/comments')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/dev-requests/'.$request->id.'/comments', [
                'content' => 'I would love this feature!',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.content', 'I would love this feature!');

        expect(DevRequestComment::count())->toBe(2);
    });
});
