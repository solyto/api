<?php

namespace App\Api\Feeds\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="FeedSubscription",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="url", type="string", format="uri"),
 *     @OA\Property(property="feed_id", type="integer"),
 *     @OA\Property(property="keywords", type="string", nullable=true),
 *     @OA\Property(property="blacklist", type="string", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class FeedSubscriptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->feed->url,
            'feed_id' => $this->feed_id,
            'keywords' => $this->keywords,
            'blacklist' => $this->blacklist,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
