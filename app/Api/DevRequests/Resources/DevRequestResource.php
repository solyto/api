<?php

namespace App\Api\DevRequests\Resources;

use App\Api\DevRequests\Models\DevRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="DevRequest",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="type", type="string", enum={"feature","bug"}),
 *     @OA\Property(property="status", type="string", enum={"backlog","pending","in-progress","completed","cancelled"}),
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
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $userVote = null;
        if ($request->user()) {
            $vote = $this->votes->firstWhere('user_id', $request->user()->id);
            $userVote = $vote?->vote_type;
        }

        $upvotes = $this->votes->where('vote_type', 'up')->count();
        $downvotes = $this->votes->where('vote_type', 'down')->count();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
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
