<?php

namespace App\Api\Libraries\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="ImdbMovieImport",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="type", type="string"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="url", type="string", format="uri"),
 *     @OA\Property(property="cover", type="string", format="uri"),
 *     @OA\Property(property="genres", type="array", @OA\Items(type="string")),
 *     @OA\Property(property="release_year", type="integer")
 * )
 */
class ImdbMovieImportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'type' => $this->getType(),
            'title' => $this->getTitle(),
            'description' => $this->getDescription(),
            'url' => $this->getLink(),
            'cover' => $this->getCover(),
            'genres' => $this->getGenres(),
            'release_year' => $this->getReleaseYear(),
        ];
    }
}
