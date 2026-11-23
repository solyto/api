<?php

namespace App\Api\DevRequests\Resources;

use App\Api\DevRequests\Models\DevRequest;
use App\Api\DevRequests\Services\DevRequestService;
use App\Shared\Helpers\ClientVersionHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="DevRequest",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="type", type="string", enum={"feature","bug"}),
 *     @OA\Property(property="status", type="string", enum={"backlog","pending","in-progress","waiting-for-deployment","waiting-for-user","completed","cancelled"}, description="By X-Client-Version (none counts as below): clients below 1.1.3 get waiting-for-deployment as in-progress, clients below 1.1.5 get waiting-for-user as pending."),
 *     @OA\Property(property="priority", type="integer", minimum=1, maximum=5, nullable=true),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="description", type="string"),
 *     @OA\Property(property="screenshot", type="string", nullable=true, description="Storage path, e.g. dev-requests/{id}/{file}"),
 *     @OA\Property(property="url", type="string", format="uri", nullable=true),
 *     @OA\Property(property="fixed_in_version", type="string", maxLength=20, nullable=true, example="1.2.0"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time"),
 *     @OA\Property(property="created_by_user_id", type="string", format="uuid", nullable=true),
 *     @OA\Property(property="upvotes", type="integer"),
 *     @OA\Property(property="downvotes", type="integer"),
 *     @OA\Property(property="score", type="integer"),
 *     @OA\Property(property="user_vote", type="string", enum={"up","down"}, nullable=true),
 *     @OA\Property(property="comment_count", type="integer")
 * )
 *
 * @mixin DevRequest
 */
class DevRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $userVote = null;
        if ($request->user()) {
            $vote = $this->votes->firstWhere('user_id', $request->user()->id);
            $userVote = $vote?->vote_type;
        }

        // Older clients don't know the waiting statuses and get the status they replaced instead.
        $clientKnowsWaitingForDeployment = ClientVersionHelper::isAtLeast($request, DevRequestService::WAITING_FOR_DEPLOYMENT_SINCE);
        $clientKnowsWaitingForUser = ClientVersionHelper::isAtLeast($request, DevRequestService::WAITING_FOR_USER_SINCE);
        $status = match (true) {
            $this->status === 'waiting-for-deployment' && ! $clientKnowsWaitingForDeployment => 'in-progress',
            $this->status === 'waiting-for-user' && ! $clientKnowsWaitingForUser => 'pending',
            default => $this->status,
        };

        $upvotes = $this->votes->where('vote_type', 'up')->count();
        $downvotes = $this->votes->where('vote_type', 'down')->count();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $status,
            'priority' => $this->priority,
            'title' => $this->title,
            'description' => $this->description,
            'screenshot' => $this->screenshot ? 'dev-requests/'.$this->id.'/'.$this->screenshot : null,
            'url' => $this->url,
            'fixed_in_version' => $this->fixed_in_version,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'created_by_user_id' => $this->created_by_user_id,
            'upvotes' => $upvotes,
            'downvotes' => $downvotes,
            'score' => $upvotes - $downvotes,
            'user_vote' => $userVote,
            'comment_count' => $this->comments_count,
        ];
    }
}
