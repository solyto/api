<?php

namespace App\Api\Libraries\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="SteamGameImport",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="url", type="string", format="uri"),
 *     @OA\Property(property="cover", type="string", format="uri"),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="release_date", type="string", nullable=true),
 *     @OA\Property(property="developer", type="string"),
 *     @OA\Property(property="publisher", type="string"),
 *     @OA\Property(property="genres", type="array", @OA\Items(type="string"))
 * )
 */
class SteamGameImportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'title' => $this->getTitle(),
            'url' => $this->getUrl(),
            'cover' => $this->getCover(),
            'description' => $this->getDescription(),
            'release_date' => $this->getReleaseDate(),
            'developer' => $this->getDeveloper(),
            'publisher' => $this->getPublisher(),
            'genres' => $this->getGenres(),
        ];
    }
}
