<?php

namespace App\Api\Libraries\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="LibraryMusicRelease",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="artist", type="string"),
 *     @OA\Property(property="artist_id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="url", type="string", format="uri"),
 *     @OA\Property(property="cover", type="string", format="uri"),
 *     @OA\Property(property="release_date", type="string", format="date", nullable=true),
 *     @OA\Property(property="provider", type="string")
 * )
 */
class LibraryMusicReleaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'artist' => $this->getArtist(),
            'artist_id' => $this->getArtistId(),
            'title' => $this->getTitle(),
            'url' => $this->getUrl(),
            'cover' => $this->getCover(),
            'release_date' => $this->getReleaseDate()?->format('Y-m-d'),
            'provider' => $this->getProvider(),
        ];
    }
}
