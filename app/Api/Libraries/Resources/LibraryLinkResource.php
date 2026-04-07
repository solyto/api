<?php

namespace App\Api\Libraries\Resources;

use App\Api\Tags\Resources\TagResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="LibraryLink",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="url", type="string", format="uri"),
 *     @OA\Property(property="is_favorite", type="boolean"),
 *     @OA\Property(property="cover_path", type="string", format="uri", nullable=true),
 *     @OA\Property(property="tags", type="array", @OA\Items(ref="#/components/schemas/Tag"), nullable=true),
 *     @OA\Property(property="category", ref="#/components/schemas/LibraryLinkCategory", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class LibraryLinkResource extends JsonResource
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
            'url' => $this->url,
            'is_favorite' => $this->is_favorite,
            'cover_path' => $this->cover_path,
            'tags' => $this->whenLoaded('tags', fn () => TagResource::collection($this->tags)
            ),
            'category' => $this->whenLoaded('category', fn () => new LibraryLinkCategoryResource($this->category)
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
