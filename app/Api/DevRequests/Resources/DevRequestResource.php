<?php

namespace App\Api\DevRequests\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="DevRequest",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="type", type="string", enum={"bug","feature","improvement"}),
 *     @OA\Property(property="status", type="string", enum={"open","in_progress","completed","rejected"}),
 *     @OA\Property(property="priority", type="string", enum={"low","medium","high"}),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="screenshot", type="string", format="uri", nullable=true),
 *     @OA\Property(property="url", type="string", format="uri", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time"),
 *     @OA\Property(property="created_by_user_id", type="integer"),
 *     @OA\Property(property="upvotes", type="integer"),
 *     @OA\Property(property="downvotes", type="integer"),
 *     @OA\Property(property="score", type="integer"),
 *     @OA\Property(property="user_vote", type="string", enum={"up","down"}, nullable=true),
 *     @OA\Property(property="comment_count", type="integer")
 * )
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
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'created_by_user_id' => $this->created_by_user_id,
            'upvotes' => $upvotes,
            'downvotes' => $downvotes,
            'score' => $upvotes - $downvotes,
            'user_vote' => $userVote,
            'comment_count' => $this->comments->count(),
        ];
    }
}
