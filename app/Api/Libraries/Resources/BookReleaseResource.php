<?php

namespace App\Api\Libraries\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="BookReleaseImport",
 *
 *     @OA\Property(property="id", type="integer", nullable=true),
 *     @OA\Property(property="author", type="string"),
 *     @OA\Property(property="author_id", type="integer", nullable=true),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="page_count", type="integer", nullable=true),
 *     @OA\Property(property="url", type="string", format="uri"),
 *     @OA\Property(property="cover", type="string", format="uri", nullable=true),
 *     @OA\Property(property="provider", type="string"),
 *     @OA\Property(property="release_date", type="string", format="date", nullable=true)
 * )
 */
class BookReleaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'author' => $this->getAuthor(),
            'author_id' => $this->getAuthorId(),
            'title' => $this->getTitle(),
            'description' => $this->getDescription(),
            'page_count' => $this->getPageCount(),
            'url' => $this->getUrl(),
            'cover' => $this->getCover(),
            'provider' => $this->getProvider(),
            'release_date' => $this->getReleaseDate()?->format('Y-m-d'),
        ];
    }
}
