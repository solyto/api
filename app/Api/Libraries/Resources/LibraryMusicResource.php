<?php

namespace App\Api\Libraries\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="LibraryMusic",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="artist", type="string"),
 *     @OA\Property(property="type", type="string"),
 *     @OA\Property(property="format", type="string", nullable=true),
 *     @OA\Property(property="condition", type="string", nullable=true),
 *     @OA\Property(property="rating", type="number", nullable=true),
 *     @OA\Property(property="acquired_where", type="string", nullable=true),
 *     @OA\Property(property="additional_info", type="string", nullable=true),
 *     @OA\Property(property="publication_year", type="integer", nullable=true),
 *     @OA\Property(property="cover", type="string", format="uri", nullable=true),
 *     @OA\Property(property="link", type="string", format="uri", nullable=true),
 *     @OA\Property(property="wishlist", type="boolean"),
 *     @OA\Property(property="genres", type="array", @OA\Items(ref="#/components/schemas/LibraryMusicGenre"), nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class LibraryMusicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'artist' => $this->artist,
            'type' => $this->type,
            'format' => $this->format,
            'condition' => $this->condition,
            'rating' => $this->rating,
            'acquired_where' => $this->acquired_where,
            'additional_info' => $this->additional_info,
            'publication_year' => $this->publication_year,
            'cover' => $this->cover_path,
            'link' => $this->link,
            'wishlist' => $this->wishlist,
            'genres' => $this->whenLoaded('genres', fn () => LibraryMusicGenreResource::collection($this->genres)
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
