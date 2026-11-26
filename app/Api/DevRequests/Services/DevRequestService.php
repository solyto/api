<?php

namespace App\Api\DevRequests\Services;

use App\Api\DevRequests\Models\DevRequest;
use App\Api\DevRequests\Models\DevRequestComment;
use App\Api\DevRequests\Models\DevRequestVote;
use App\Api\DevRequests\Notifications\DevRequestCommentNotification;
use App\Api\DevRequests\Notifications\DevRequestCreatedNotification;
use App\Api\Users\Models\User;
use App\Shared\Notifications\Channels\NtfyChannel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class DevRequestService
{
    // Older clients don't know these statuses and get `in-progress` / `pending` instead (STANDARDS 13.3).
    public const string WAITING_FOR_DEPLOYMENT_SINCE = '1.1.3';
    public const string WAITING_FOR_USER_SINCE = '1.1.5';

    public function __construct(private readonly DevRequestScreenshotService $screenshotService) {}

    public function list(): Collection
    {
        // Newest first from the query; the stable score sort keeps that order among ties.
        return DevRequest::with('votes')
            ->withCount('comments')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->sortByDesc(fn (DevRequest $devRequest) => $this->score($devRequest))
            ->values();
    }

    public function notice(): ?string
    {
        $path = config('app.dev_request_notice');
        $text = is_file($path) ? trim((string) file_get_contents($path)) : '';

        return $text === '' ? null : $text;
    }

    public function find(int $id): ?DevRequest
    {
        return DevRequest::with('votes')->withCount('comments')->find($id);
    }

    private function score(DevRequest $devRequest): int
    {
        return $devRequest->votes->where('vote_type', 'up')->count()
            - $devRequest->votes->where('vote_type', 'down')->count();
    }

    public function create(User $user, array $data): DevRequest
    {
        $screenshot = empty($data['screenshot']) ? null : $this->screenshotService->decode($data['screenshot']);
        unset($data['screenshot'], $data['screenshot_name']);

        $devRequest = DB::transaction(function () use ($user, $data, $screenshot) {
            $devRequest = DevRequest::create([...$data, 'created_by_user_id' => $user->id]);

            if ($screenshot !== null) {
                $fileName = $this->screenshotService->save($devRequest->id, $screenshot['bytes'], $screenshot['extension']);
                if ($fileName === false) {
                    throw new \RuntimeException('Could not store the dev request screenshot.');
                }

                $devRequest->update(['screenshot' => $fileName]);
            }

            return $devRequest;
        });

        $this->notifyOperator($devRequest);

        // refresh() picks up the database defaults, e.g. the initial status.
        $devRequest->refresh()->load('votes')->loadCount('comments');

        return $devRequest;
    }

    private function notifyOperator(DevRequest $devRequest): void
    {
        $topic = config('services.ntfy.topics.dev_requests') ?: config('services.ntfy.topic');

        Notification::route(NtfyChannel::class, $topic)->notify(new DevRequestCreatedNotification($devRequest->title));
    }

    // Dev requests are shared community data (STANDARDS 12.3) and their creator FK has no onDelete cascade:
    // the attribution is anonymised so the requests, with other users' comments and votes, survive the account.
    public function anonymiseCreator(User $user): int
    {
        return DevRequest::where('created_by_user_id', $user->id)
            ->update(['created_by_user_id' => null]);
    }

    public function update(DevRequest $devRequest, array $data): DevRequest
    {
        $devRequest->update($data);
        $devRequest->load('votes')->loadCount('comments');

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

        $devRequest->load('votes')->loadCount('comments');

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
