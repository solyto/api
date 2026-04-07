<?php

namespace App\Api\Feeds\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="FeedItem",
 *
 *     @OA\Property(property="id", type="string"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="link", type="string", format="uri"),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="image_url", type="string", format="uri", nullable=true),
 *     @OA\Property(property="feed_id", type="integer"),
 *     @OA\Property(property="published_at", type="string", format="date-time", nullable=true)
 * )
 */
class FeedItemResource extends JsonResource
{
    public static string $feedId;

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
            'link' => $this->link,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'feed_id' => $this->feed_id,
            'published_at' => $this->published_at,
        ];
    }
}
