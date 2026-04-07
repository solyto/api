<?php

namespace App\Api\Libraries\Resources;

use App\Api\Tags\Resources\TagResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="LibraryQuote",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="summary", type="string", nullable=true),
 *     @OA\Property(property="author", type="string", nullable=true),
 *     @OA\Property(property="quote", type="string"),
 *     @OA\Property(property="source", type="string", nullable=true),
 *     @OA\Property(property="tags", type="array", @OA\Items(ref="#/components/schemas/Tag"), nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class LibraryQuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'summary' => $this->summary,
            'author' => $this->author,
            'quote' => $this->quote,
            'source' => $this->source,
            'tags' => $this->whenLoaded('tags', fn () => TagResource::collection($this->tags)
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
