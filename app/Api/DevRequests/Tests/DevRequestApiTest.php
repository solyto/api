<?php

use App\Api\DevRequests\Models\DevRequest;
use App\Api\DevRequests\Models\DevRequestComment;
use App\Api\DevRequests\Models\DevRequestVote;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

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

    it('limits how many dev requests a user can file per hour', function () {
        Notification::fake();
        $user = makeUser();
        $payload = ['title' => 'Dark mode', 'description' => 'Add a dark theme', 'type' => 'feature'];

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user, 'sanctum')->postJson('/api/v1/dev-requests', $payload)->assertStatus(201);
        }

        expectApiError($this->actingAs($user, 'sanctum')->postJson('/api/v1/dev-requests', $payload), 429);
        expect(DevRequest::count())->toBe(10);
    });

    it('rejects invalid dev requests', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/dev-requests', ['title' => ''])
            ->assertStatus(422);
    });
});

describe('DevRequest fixed_in_version', function () {
    it('lets an admin set fixed_in_version', function () {
        $admin = makeUser(['role' => 'admin']);
        $devRequest = DevRequest::factory()->create(['status' => 'completed']);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/dev-requests/'.$devRequest->id, ['fixed_in_version' => '1.2.0'])
            ->assertStatus(200)
            ->assertJsonPath('data.fixed_in_version', '1.2.0');

        expect($devRequest->fresh()->fixed_in_version)->toBe('1.2.0');
    });

    it('lets an admin clear fixed_in_version with null', function () {
        $admin = makeUser(['role' => 'admin']);
        $devRequest = DevRequest::factory()->create(['status' => 'completed', 'fixed_in_version' => '1.2.0']);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/dev-requests/'.$devRequest->id, ['fixed_in_version' => null])
            ->assertStatus(200)
            ->assertJsonPath('data.fixed_in_version', null);

        expect($devRequest->fresh()->fixed_in_version)->toBeNull();
    });

    it('forbids a non-admin from setting fixed_in_version', function () {
        $user = makeUser();
        $devRequest = DevRequest::factory()->create(['status' => 'completed', 'fixed_in_version' => '1.1.0']);

        expectApiError($this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/dev-requests/'.$devRequest->id, ['fixed_in_version' => '1.2.0']), 403);

        expect($devRequest->fresh()->fixed_in_version)->toBe('1.1.0');
    });

    it('rejects an invalid fixed_in_version format', function (string $version) {
        $admin = makeUser(['role' => 'admin']);
        $devRequest = DevRequest::factory()->create(['status' => 'completed']);

        expectApiError($this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/dev-requests/'.$devRequest->id, ['fixed_in_version' => $version]), 422);

        expect($devRequest->fresh()->fixed_in_version)->toBeNull();
    })->with(['v1.2', '1.2-beta', '1..2', '1.2.', '123456789.123456789.1']);
});

describe('DevRequest waiting-for-deployment status', function () {
    it('lets an admin set the status to waiting-for-deployment', function () {
        $admin = makeUser(['role' => 'admin']);
        $devRequest = DevRequest::factory()->inProgress()->create();

        $this->actingAs($admin, 'sanctum')
            ->withHeaders(['X-Client-Version' => '1.1.3'])
            ->putJson('/api/v1/dev-requests/'.$devRequest->id, ['status' => 'waiting-for-deployment'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'waiting-for-deployment');

        expect($devRequest->fresh()->status)->toBe('waiting-for-deployment');
    });

    it('returns waiting-for-deployment to clients from 1.1.3 on', function (string $version) {
        $user = makeUser();
        DevRequest::factory()->waitingForDeployment()->create();

        $this->actingAs($user, 'sanctum')
            ->withHeaders(['X-Client-Version' => $version])
            ->getJson('/api/v1/dev-requests')
            ->assertStatus(200)
            ->assertJsonPath('data.0.status', 'waiting-for-deployment');
    })->with(['1.1.3', '1.2.0']);

    it('returns waiting-for-deployment as in-progress to older or unknown clients', function (?string $version) {
        $user = makeUser();
        $devRequest = DevRequest::factory()->waitingForDeployment()->create();
        $headers = $version === null ? [] : ['X-Client-Version' => $version];

        $this->actingAs($user, 'sanctum')
            ->withHeaders($headers)
            ->getJson('/api/v1/dev-requests')
            ->assertStatus(200)
            ->assertJsonPath('data.0.status', 'in-progress');

        expect($devRequest->fresh()->status)->toBe('waiting-for-deployment');
    })->with(['1.1.2', '1.0.15', 'garbage', null]);

    it('leaves other statuses alone for older clients', function () {
        $user = makeUser();
        DevRequest::factory()->completed()->create();

        $this->actingAs($user, 'sanctum')
            ->withHeaders(['X-Client-Version' => '1.0.15'])
            ->getJson('/api/v1/dev-requests')
            ->assertStatus(200)
            ->assertJsonPath('data.0.status', 'completed');
    });

    it('forbids a non-admin from setting the status to waiting-for-deployment', function () {
        $user = makeUser();
        $devRequest = DevRequest::factory()->inProgress()->create();

        expectApiError($this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/dev-requests/'.$devRequest->id, ['status' => 'waiting-for-deployment']), 403);

        expect($devRequest->fresh()->status)->toBe('in-progress');
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

    it('lists dev requests by score, newest first among ties', function () {
        $user = makeUser();
        $older = DevRequest::factory()->create(['created_at' => now()->subDays(2)]);
        $newer = DevRequest::factory()->create(['created_at' => now()->subDay()]);
        $top = DevRequest::factory()->create(['created_at' => now()->subDays(3)]);
        $bottom = DevRequest::factory()->create();

        DevRequestVote::factory()->count(2)->upvote()->create(['dev_request_id' => $top->id]);
        DevRequestVote::factory()->downvote()->create(['dev_request_id' => $bottom->id]);

        $ids = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dev-requests')
            ->assertStatus(200)
            ->json('data.*.id');

        expect($ids)->toBe([$top->id, $newer->id, $older->id, $bottom->id]);
    });

    it('rejects an unknown vote', function () {
        $user = makeUser();
        $devRequest = DevRequest::factory()->create();

        expectApiError($this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/dev-requests/'.$devRequest->id.'/vote', ['vote' => 'sideways']), 422);

        expect(DevRequestVote::count())->toBe(0);
    });
});

describe('DevRequest screenshot', function () {
    $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    $store = fn ($test, array $screenshot) => $test->actingAs(makeUser(), 'sanctum')
        ->postJson('/api/v1/dev-requests', [
            'title' => 'Broken button',
            'description' => 'It does nothing',
            'type' => 'bug',
            ...$screenshot,
        ]);

    beforeEach(function () {
        Storage::fake('public');
        Notification::fake();
    });

    it('rejects a screenshot that is not an image data URI', function (string $screenshot) use ($store) {
        expectApiError($store($this, ['screenshot' => $screenshot, 'screenshot_name' => 'x.json']), 422);

        expect(DevRequest::count())->toBe(0);
        expect(Storage::disk('public')->allFiles())->toBe([]);
    })->with([
        'local path' => fn () => base_path('composer.json'),
        'http url' => 'http://127.0.0.1/secret',
        'data stream' => 'data://text/plain;base64,'.base64_encode('<?php echo 1;'),
        'image prefix with non-image bytes' => 'data:image/png;base64,'.base64_encode('<?php echo 1;'),
    ]);

    it('stores the screenshot with the extension of the image, not of the client name', function () use ($store, $png) {
        $id = $store($this, ['screenshot' => $png, 'screenshot_name' => 'x.php'])
            ->assertStatus(201)
            ->assertJsonPath('data.screenshot', fn (string $path) => str_ends_with($path, '/screenshot.png'))
            ->json('data.id');

        expect(Storage::disk('public')->allFiles())->toBe(['dev-requests/'.$id.'/screenshot.png']);
        expect(DevRequest::find($id)->screenshot)->toBe('screenshot.png');
    });

    it('takes the extension from the image bytes, not from the declared type', function () use ($store, $png) {
        $id = $store($this, ['screenshot' => str_replace('image/png', 'image/jpeg', $png)])
            ->assertStatus(201)
            ->json('data.id');

        expect(Storage::disk('public')->allFiles())->toBe(['dev-requests/'.$id.'/screenshot.png']);
    });

    it('stores a screenshot sent without a name', function () use ($store, $png) {
        $store($this, ['screenshot' => $png])->assertStatus(201);

        expect(DevRequest::count())->toBe(1);
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
            'status' => 'pending',
        ]);

        expectApiError($this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/dev-requests/'.$devRequest->id, ['status' => 'in-progress']), 403);

        expect($devRequest->fresh()->status)->toBe('pending');
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

    it('sends to the dev requests topic when one is set', function () use ($store) {
        config([
            'services.ntfy.url' => 'https://ntfy.example.test',
            'services.ntfy.topic' => 'solyto-admin',
            'services.ntfy.topics.dev_requests' => 'solyto-dev-requests',
        ]);
        Http::fake();

        $store($this)->assertStatus(201);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['topic'] === 'solyto-dev-requests');
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
