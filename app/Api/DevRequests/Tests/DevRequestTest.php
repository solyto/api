<?php

use App\Api\DevRequests\Models\DevRequest;
use App\Api\DevRequests\Models\DevRequestComment;
use App\Api\DevRequests\Models\DevRequestVote;
use App\Api\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('DevRequest Factory', function () {
    it('creates a valid dev request', function () {
        $request = DevRequest::factory()->create();

        expect($request->type)->toBeString();
        expect($request->status)->toBeString();
        expect($request->title)->toBeString();
        expect($request->description)->toBeString();
        expect($request->priority)->toBeString();
        expect($request->created_by_user_id)->not()->toBeNull();
    });

    it('creates a feature request', function () {
        $request = DevRequest::factory()->feature()->create();

        expect($request->type)->toBe('feature');
    });

    it('creates a bug request', function () {
        $request = DevRequest::factory()->bug()->create();

        expect($request->type)->toBe('bug');
    });

    it('creates an improvement request', function () {
        $request = DevRequest::factory()->improvement()->create();

        expect($request->type)->toBe('improvement');
    });

    it('creates an open request', function () {
        $request = DevRequest::factory()->open()->create();

        expect($request->status)->toBe('open');
    });

    it('creates an in progress request', function () {
        $request = DevRequest::factory()->inProgress()->create();

        expect($request->status)->toBe('in_progress');
    });

    it('creates a completed request', function () {
        $request = DevRequest::factory()->completed()->create();

        expect($request->status)->toBe('completed');
    });

    it('creates a rejected request', function () {
        $request = DevRequest::factory()->rejected()->create();

        expect($request->status)->toBe('rejected');
    });

    it('creates a high priority request', function () {
        $request = DevRequest::factory()->highPriority()->create();

        expect($request->priority)->toBe('high');
    });

    it('creates a request with url', function () {
        $request = DevRequest::factory()->withUrl()->create();

        expect($request->url)->not()->toBeNull();
    });

    it('creates a request for user', function () {
        $user = User::factory()->create();
        $request = DevRequest::factory()->create([
            'created_by_user_id' => $user->id,
        ]);

        expect($request->created_by_user_id)->toBe($user->id);
    });
});

describe('DevRequestComment Factory', function () {
    it('creates a valid comment', function () {
        $user = User::factory()->create();
        $request = DevRequest::factory()->create();
        $comment = DevRequestComment::factory()->forDevRequestAndUser($request, $user)->create();

        expect($comment->content)->toBeString();
        expect($comment->dev_request_id)->toBe($request->id);
        expect($comment->user_id)->toBe($user->id);
    });

    it('creates a short comment', function () {
        $comment = DevRequestComment::factory()->short()->create();

        expect(strlen($comment->content))->toBeLessThan(100);
    });

    it('creates a comment with custom content', function () {
        $content = 'Custom comment content';
        $comment = DevRequestComment::factory()->withContent($content)->create();

        expect($comment->content)->toBe($content);
    });
});

describe('DevRequestVote Factory', function () {
    it('creates a valid vote', function () {
        $user = User::factory()->create();
        $request = DevRequest::factory()->create();
        $vote = DevRequestVote::factory()->forDevRequestAndUser($request, $user)->create();

        expect($vote->vote_type)->toBeString();
        expect($vote->dev_request_id)->toBe($request->id);
        expect($vote->user_id)->toBe($user->id);
    });

    it('creates an upvote', function () {
        $vote = DevRequestVote::factory()->upvote()->create();

        expect($vote->vote_type)->toBe('up');
    });

    it('creates a downvote', function () {
        $vote = DevRequestVote::factory()->downvote()->create();

        expect($vote->vote_type)->toBe('down');
    });
});

describe('DevRequest Model', function () {
    it('has correct fillable attributes', function () {
        $request = new DevRequest;

        expect($request->getFillable())->toContain('type');
        expect($request->getFillable())->toContain('status');
        expect($request->getFillable())->toContain('title');
        expect($request->getFillable())->toContain('description');
        expect($request->getFillable())->toContain('priority');
        expect($request->getFillable())->toContain('created_by_user_id');
    });

    it('has comments relationship', function () {
        $request = DevRequest::factory()->create();

        expect($request->comments)->toHaveCount(0);
    });

    it('has votes relationship', function () {
        $request = DevRequest::factory()->create();

        expect($request->votes)->toHaveCount(0);
    });

    it('belongs to creator', function () {
        $user = User::factory()->create();
        $request = DevRequest::factory()->create([
            'created_by_user_id' => $user->id,
        ]);

        expect($request->createdByUser->id)->toBe($user->id);
    });

    it('can have comments', function () {
        $user = User::factory()->create();
        $request = DevRequest::factory()->create();
        DevRequestComment::factory()->forDevRequestAndUser($request, $user)->create();

        expect($request->comments)->toHaveCount(1);
    });

    it('can have votes', function () {
        $user = User::factory()->create();
        $request = DevRequest::factory()->create();
        DevRequestVote::factory()->forDevRequestAndUser($request, $user)->create();
        DevRequestVote::factory()->forDevRequestAndUser($request, $user)->create();

        expect($request->votes)->toHaveCount(2);
    });
});
