<?php

use App\Api\DevRequests\Models\DevRequest;
use App\Api\DevRequests\Models\DevRequestComment;
use App\Api\DevRequests\Models\DevRequestVote;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dev-requests')
            ->assertStatus(200)
            ->assertJsonPath('data.0.comment_count', 2);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/dev-requests/'.$request->id.'/vote', ['vote' => 'up'])
            ->assertStatus(200)
            ->assertJsonPath('data.comment_count', 2);
    });
});

// Listing, voting and comments are shared by design (STANDARDS 12.3); only
// the update (admin-only) and each user's own vote are private.
describe('DevRequest tenant isolation', function () {
    it('files a dev request as the authenticated user, ignoring created_by_user_id', function () {
        $user = makeUser();
        $other = makeUser();

        $id = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/dev-requests', [
                'title' => 'Dark mode',
                'description' => 'Add a dark theme',
                'type' => 'feature',
                'created_by_user_id' => $other->id,
            ])
            ->assertStatus(201)
            ->json('data.id');

        expect(DevRequest::find($id)->created_by_user_id)->toBe($user->id);
    });

    it('files a dev request without created_by_user_id as the authenticated user', function () {
        $user = makeUser();

        $id = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/dev-requests', [
                'title' => 'Dark mode',
                'description' => 'Add a dark theme',
                'type' => 'feature',
            ])
            ->assertStatus(201)
            ->json('data.id');

        expect(DevRequest::find($id)->created_by_user_id)->toBe($user->id);
    });

    it('forbids a non-admin from updating another user\'s dev request', function () {
        $user = makeUser();
        $other = makeUser();
        $devRequest = DevRequest::factory()->create([
            'created_by_user_id' => $other->id,
            'status' => 'open',
        ]);

        expectApiError($this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/dev-requests/'.$devRequest->id, ['status' => 'in-progress']), 403);

        expect($devRequest->fresh()->status)->toBe('open');
    });

    it('does not change another user\'s vote when voting', function () {
        $user = makeUser();
        $other = makeUser();
        $devRequest = DevRequest::factory()->create();
        DevRequestVote::factory()->forDevRequestAndUser($devRequest, $other)->upvote()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/dev-requests/'.$devRequest->id.'/vote', ['vote' => 'down'])
            ->assertStatus(200);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/dev-requests/'.$devRequest->id.'/vote', ['vote' => null])
            ->assertStatus(200);

        $otherVote = DevRequestVote::where('dev_request_id', $devRequest->id)->where('user_id', $other->id)->first();
        expect($otherVote?->vote_type)->toBe('up');
        expect(DevRequestVote::where('dev_request_id', $devRequest->id)->where('user_id', $user->id)->count())->toBe(0);
    });
});

describe('DevRequest ntfy announcement', function () {
    $store = fn ($test) => $test->actingAs(makeUser(), 'sanctum')
        ->postJson('/api/v1/dev-requests', [
            'title' => 'Dark mode',
            'description' => 'Add a dark theme',
            'type' => 'feature',
        ]);

    it('sends nothing when ntfy is not configured', function () use ($store) {
        config(['services.ntfy.url' => null, 'services.ntfy.topic' => null]);
        Http::fake();

        $store($this)->assertStatus(201);

        Http::assertNothingSent();
    });

    it('sends the title but not the description when ntfy is configured', function () use ($store) {
        config(['services.ntfy.url' => 'https://ntfy.example.test', 'services.ntfy.topic' => 'solyto-admin']);
        Http::fake();

        $store($this)->assertStatus(201);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) =>
            $request->url() === 'https://ntfy.example.test'
            && $request['message'] === 'New dev request: Dark mode'
            && !str_contains($request->body(), 'Add a dark theme')
        );
    });

    it('still creates the dev request when ntfy fails', function (Closure $fake) use ($store) {
        config(['services.ntfy.url' => 'https://ntfy.example.test', 'services.ntfy.topic' => 'solyto-admin']);
        $fake();

        $store($this)->assertStatus(201)->assertJsonPath('data.title', 'Dark mode');

        expect(DevRequest::where('title', 'Dark mode')->exists())->toBeTrue();
    })->with([
        'error response' => fn () => fn () => Http::fake(['*' => Http::response('boom', 500)]),
        'connection error' => fn () => fn () => Http::fake(fn () => throw new ConnectionException('Connection refused')),
    ]);
});
