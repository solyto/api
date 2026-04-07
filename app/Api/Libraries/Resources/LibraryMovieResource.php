<?php

namespace App\Api\Libraries\Resources;

use App\Api\Tags\Resources\TagResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="LibraryMovie",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="rating", type="number", nullable=true),
 *     @OA\Property(property="cover", type="string", format="uri", nullable=true),
 *     @OA\Property(property="link", type="string", format="uri", nullable=true),
 *     @OA\Property(property="wishlist", type="boolean"),
 *     @OA\Property(property="category", type="string", nullable=true),
 *     @OA\Property(property="publication_year", type="integer", nullable=true),
 *     @OA\Property(property="genres", type="array", @OA\Items(ref="#/components/schemas/LibraryMovieGenre"), nullable=true),
 *     @OA\Property(property="tags", type="array", @OA\Items(ref="#/components/schemas/Tag"), nullable=true),
 *     @OA\Property(property="started_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="finished_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class LibraryMovieResource extends JsonResource
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
            'category' => $this->category,
            'publication_year' => $this->publication_year,
            'genres' => $this->whenLoaded('genres', fn () => LibraryMovieResource::collection($this->genres)
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
