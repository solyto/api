<?php

namespace App\Api\Libraries\Resources;

use App\Api\Tags\Resources\TagResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="LibraryGame",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="rating", type="number", nullable=true),
 *     @OA\Property(property="cover", type="string", format="uri", nullable=true),
 *     @OA\Property(property="link", type="string", format="uri", nullable=true),
 *     @OA\Property(property="wishlist", type="boolean"),
 *     @OA\Property(property="platform", type="string", nullable=true),
 *     @OA\Property(property="developer", type="string", nullable=true),
 *     @OA\Property(property="publisher", type="string", nullable=true),
 *     @OA\Property(property="publication_year", type="integer", nullable=true),
 *     @OA\Property(property="playtime_hours", type="number", nullable=true),
 *     @OA\Property(property="completed", type="boolean"),
 *     @OA\Property(property="genres", type="array", @OA\Items(ref="#/components/schemas/LibraryGameGenre"), nullable=true),
 *     @OA\Property(property="tags", type="array", @OA\Items(ref="#/components/schemas/Tag"), nullable=true),
 *     @OA\Property(property="started_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="finished_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class LibraryGameResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'rating' => $this->rating,
            'cover' => $this->cover_path,
            'link' => $this->link,
            'wishlist' => $this->wishlist,
            'platform' => $this->platform,
            'developer' => $this->developer,
            'publisher' => $this->publisher,
            'publication_year' => $this->publication_year,
            'playtime_hours' => $this->playtime_hours,
            'completed' => $this->completed,
            'genres' => $this->whenLoaded('genres', fn () => LibraryGameGenreResource::collection($this->genres)
            ),
            'tags' => $this->whenLoaded('tags', fn () => TagResource::collection($this->tags)
            ),
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
