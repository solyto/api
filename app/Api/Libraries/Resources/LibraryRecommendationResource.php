<?php

namespace App\Api\Libraries\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="LibraryRecommendation",
 *
 *     @OA\Property(property="id", type="integer", nullable=true),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="creator", type="string"),
 *     @OA\Property(property="cover", type="string", format="uri", nullable=true),
 *     @OA\Property(property="link", type="string", format="uri", nullable=true)
 * )
 */
class LibraryRecommendationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'] ?? null,
            'title' => $this['title'],
            'creator' => $this['creator'],
            'cover' => $this['cover'] ?? null,
            'link' => $this['link'] ?? null,
        ];
    }
}
