<?php

namespace App\Api\Libraries\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="GameReleaseImport",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="url", type="string", format="uri"),
 *     @OA\Property(property="provider", type="string"),
 *     @OA\Property(property="cover", type="string", format="uri", nullable=true),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="publication_year", type="integer", nullable=true),
 *     @OA\Property(property="developer", type="string", nullable=true),
 *     @OA\Property(property="publisher", type="string", nullable=true),
 *     @OA\Property(property="genres", type="array", @OA\Items(type="string"))
 * )
 */
class GameReleaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'title' => $this->getTitle(),
            'url' => $this->getUrl(),
            'provider' => $this->getProvider(),
            'cover' => $this->getCover(),
            'description' => $this->getDescription(),
            'publication_year' => $this->getPublicationYear(),
            'developer' => $this->getDeveloper(),
            'publisher' => $this->getPublisher(),
            'genres' => $this->getGenres(),
        ];
    }
}
