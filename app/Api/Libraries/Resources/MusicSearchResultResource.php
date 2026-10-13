<?php

namespace App\Api\Libraries\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="MusicSearchResult",
 *
 *     @OA\Property(property="id", oneOf={@OA\Schema(type="integer"), @OA\Schema(type="string")}),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="artist", type="string", nullable=true),
 *     @OA\Property(property="cover", type="string", format="uri", nullable=true),
 *     @OA\Property(property="release_year", type="integer", nullable=true),
 *     @OA\Property(property="provider", type="string"),
 *     @OA\Property(property="url", type="string", format="uri")
 * )
 */
class MusicSearchResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->getId(),
            'title'        => $this->getTitle(),
            'artist'       => $this->getArtist(),
            'cover'        => $this->getCover(),
            'release_year' => $this->getReleaseYear(),
            'provider'     => $this->getProvider(),
            'url'          => $this->getUrl(),
        ];
    }
}
