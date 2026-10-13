<?php

namespace App\Api\Libraries\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="BookSearchResult",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="author", type="string", nullable=true),
 *     @OA\Property(property="cover", type="string", format="uri", nullable=true),
 *     @OA\Property(property="release_year", type="integer", nullable=true),
 *     @OA\Property(property="provider", type="string"),
 *     @OA\Property(property="url", type="string", format="uri")
 * )
 */
class BookSearchResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->getId(),
            'title'        => $this->getTitle(),
            'author'       => $this->getAuthor(),
            'cover'        => $this->getCover(),
            'release_year' => $this->getReleaseYear(),
            'provider'     => $this->getProvider(),
            'url'          => $this->getUrl(),
        ];
    }
}
