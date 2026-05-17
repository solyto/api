<?php

namespace App\Api\Libraries\Resources;

use App\Api\Tags\Resources\TagResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="LibraryBook",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="author", type="string", nullable=true),
 *     @OA\Property(property="series", type="string", nullable=true),
 *     @OA\Property(property="volume", type="string", nullable=true),
 *     @OA\Property(property="pages", type="integer", nullable=true),
 *     @OA\Property(property="current_page", type="integer", nullable=true),
 *     @OA\Property(property="rating", type="number", nullable=true),
 *     @OA\Property(property="lent_to", type="string", nullable=true),
 *     @OA\Property(property="is_where", type="string", nullable=true),
 *     @OA\Property(property="cover", type="string", format="uri", nullable=true),
 *     @OA\Property(property="link", type="string", format="uri", nullable=true),
 *     @OA\Property(property="wishlist", type="boolean"),
 *     @OA\Property(property="publication_year", type="integer", nullable=true),
 *     @OA\Property(property="summary", type="string", nullable=true),
 *     @OA\Property(property="genres", type="array", @OA\Items(ref="#/components/schemas/LibraryBookGenre"), nullable=true),
 *     @OA\Property(property="tags", type="array", @OA\Items(ref="#/components/schemas/Tag"), nullable=true),
 *     @OA\Property(property="started_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="finished_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class LibraryBookResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'author' => $this->author,
            'series' => $this->series,
            'volume' => $this->volume,
            'pages' => $this->pages,
            'current_page' => $this->current_page,
            'rating' => $this->rating,
            'lent_to' => $this->lent_to,
            'is_where' => $this->is_where,
            'cover' => $this->cover_path,
            'link' => $this->link,
            'wishlist' => $this->wishlist,
            'publication_year' => $this->publication_year,
            'summary' => $this->summary,
            'genres' => $this->whenLoaded('genres', fn () => LibraryBookGenreResource::collection($this->genres)),
            'tags' => $this->whenLoaded('tags', fn () => TagResource::collection($this->tags)),
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
