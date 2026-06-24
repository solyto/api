<?php

namespace App\Api\Libraries\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="LibraryMovieRelease",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="cover", type="string", format="uri", nullable=true),
 *     @OA\Property(property="release_year", type="integer", nullable=true),
 *     @OA\Property(property="type", type="string", enum={"movie", "tv"}),
 *     @OA\Property(property="url", type="string", format="uri"),
 *     @OA\Property(property="provider", type="string")
 * )
 */
class LibraryMovieReleaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'title' => $this->getTitle(),
            'description' => $this->getDescription(),
            'cover' => $this->getCover(),
            'release_year' => $this->getReleaseYear(),
            'type' => $this->getType(),
            'url' => $this->getUrl(),
            'provider' => $this->getProvider(),
        ];
    }
}
