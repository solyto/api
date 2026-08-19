<?php

namespace App\Api\Libraries\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="Author",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="name", type="string"),
 *     @OA\Property(property="photo", type="string", nullable=true),
 *     @OA\Property(property="bio", type="string", nullable=true),
 *     @OA\Property(property="hardcover_id", type="integer", nullable=true),
 *     @OA\Property(property="is_favorite", type="boolean"),
 *     @OA\Property(property="books_count", type="integer", nullable=true),
 *     @OA\Property(property="books", type="array", @OA\Items(ref="#/components/schemas/LibraryBook"), nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class AuthorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'photo' => $this->photo,
            'bio' => $this->bio,
            'hardcover_id' => $this->hardcover_id,
            'is_favorite' => $this->is_favorite,
            'books_count' => $this->when(
                $this->books_count !== null || $this->relationLoaded('books'),
                fn () => $this->books_count ?? $this->books->count()
            ),
            'books' => $this->whenLoaded('books', fn () => LibraryBookResource::collection($this->books)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
