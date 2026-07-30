<?php

namespace App\Api\Libraries\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="LibraryYoutubeVideo",
 *
 *     @OA\Property(property="id", type="string"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="video_id", type="string", nullable=true),
 *     @OA\Property(property="url", type="string", format="uri"),
 *     @OA\Property(property="cover", type="string", format="uri", nullable=true),
 *     @OA\Property(property="is_favorite", type="boolean"),
 *     @OA\Property(property="sort_order", type="integer"),
 *     @OA\Property(property="category", ref="#/components/schemas/LibraryYoutubeCategory", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class LibraryYoutubeVideoResource extends JsonResource
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
            'video_id' => $this->video_id,
            'url' => $this->url,
            'cover' => $this->cover_path,
            'is_favorite' => $this->is_favorite,
            'sort_order' => $this->sort_order,
            'category' => $this->whenLoaded('category', fn () => new LibraryYoutubeCategoryResource($this->category)
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
