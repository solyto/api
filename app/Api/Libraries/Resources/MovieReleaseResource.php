<?php

namespace App\Api\Libraries\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="MovieReleaseImport",
 *
 *     @OA\Property(property="id", type="string"),
 *     @OA\Property(property="type", type="string", nullable=true),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="url", type="string", format="uri"),
 *     @OA\Property(property="cover", type="string", format="uri", nullable=true),
 *     @OA\Property(property="provider", type="string"),
 *     @OA\Property(property="release_year", type="integer", nullable=true),
 *     @OA\Property(property="runtime", type="integer", nullable=true),
 *     @OA\Property(property="genres", type="array", @OA\Items(type="string"))
 * )
 */
class MovieReleaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'type' => $this->getType(),
            'title' => $this->getTitle(),
            'description' => $this->getDescription(),
            'url' => $this->getUrl(),
            'cover' => $this->getCover(),
            'provider' => $this->getProvider(),
            'release_year' => $this->getReleaseYear(),
            'runtime' => $this->getRuntime(),
            'genres' => $this->getGenres(),
        ];
    }
}
