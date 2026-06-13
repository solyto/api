<?php

namespace App\Api\DevRequests\Services;

use App\Api\DevRequests\Models\DevRequest;
use App\Api\DevRequests\Models\DevRequestComment;
use App\Api\DevRequests\Models\DevRequestVote;
use App\Api\DevRequests\Notifications\DevRequestCommentNotification;
use App\Api\Users\Models\User;
use Illuminate\Support\Collection;

class DevRequestService
{
    public function __construct(private readonly DevRequestScreenshotService $screenshotService) {}

    public function list(): Collection
    {
        return DevRequest::with(['votes', 'comments'])->get();
    }

    public function create(array $data): DevRequest
    {
        $screenshot = $data['screenshot'] ?? null;
        $screenshotName = $data['screenshot_name'] ?? null;
        unset($data['screenshot'], $data['screenshot_name']);

        $devRequest = DevRequest::create($data);

        if (!empty($screenshot)) {
            $this->screenshotService->save($devRequest->id, $screenshotName, $screenshot);
            $devRequest->update(['screenshot' => $this->screenshotService->getFileName($screenshotName)]);
        }

        $devRequest->load(['votes', 'comments']);

        return $devRequest;
    }

    public function update(DevRequest $devRequest, array $data): DevRequest
    {
        $devRequest->update($data);
        $devRequest->load(['votes', 'comments']);

        return $devRequest;
    }

    public function destroy(DevRequest $devRequest): void
    {
        $devRequest->delete();
    }

    public function vote(DevRequest $devRequest, User $user, ?string $voteType): DevRequest
    {
        $existingVote = DevRequestVote::where('dev_request_id', $devRequest->id)
            ->where('user_id', $user->id)
            ->first();

        if ($voteType === null || ($existingVote && $existingVote->vote_type === $voteType)) {
            $existingVote?->delete();
        } elseif ($existingVote) {
            $existingVote->update(['vote_type' => $voteType]);
        } else {
            DevRequestVote::create([
                'dev_request_id' => $devRequest->id,
                'user_id' => $user->id,
                'vote_type' => $voteType,
            ]);
        }

        $devRequest->load(['votes', 'comments']);

        return $devRequest;
    }

    public function listComments(DevRequest $devRequest): Collection
    {
        return DevRequestComment::with('user')
            ->where('dev_request_id', $devRequest->id)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    public function createComment(DevRequest $devRequest, User $user, string $content): DevRequestComment
    {
        $comment = DevRequestComment::create([
            'dev_request_id' => $devRequest->id,
            'user_id' => $user->id,
            'content' => $content,
        ]);

        $comment->load('user');

        if ($devRequest->created_by_user_id && $devRequest->created_by_user_id !== $user->id) {
            $devRequest->load('createdByUser');
            $devRequest->createdByUser->notify(
                new DevRequestCommentNotification($devRequest, $user)
            );
        }

        return $comment;
    }
}
