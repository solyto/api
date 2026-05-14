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
 *     @OA\Property(property="overview", type="string", nullable=true),
 *     @OA\Property(property="poster", type="string", format="uri", nullable=true),
 *     @OA\Property(property="release_date", type="string", format="date"),
 *     @OA\Property(property="type", type="string", enum={"movie", "tv"}),
 *     @OA\Property(property="url", type="string", format="uri")
 * )
 */
class LibraryMovieReleaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'title' => $this->getTitle(),
            'overview' => $this->getOverview(),
            'poster' => $this->getPoster(),
            'release_date' => $this->getReleaseDate()->format('Y-m-d'),
            'type' => $this->getType(),
            'url' => $this->getUrl(),
        ];
    }
}
