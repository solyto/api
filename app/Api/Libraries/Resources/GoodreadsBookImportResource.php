<?php

namespace App\Api\Libraries\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="GoodreadsBookImport",
 *
 *     @OA\Property(property="author", type="string"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="page_count", type="integer"),
 *     @OA\Property(property="url", type="string", format="uri"),
 *     @OA\Property(property="cover", type="string", format="uri"),
 *     @OA\Property(property="release_date", type="string", format="date")
 * )
 */
class GoodreadsBookImportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'author' => $this->getAuthor(),
            'title' => $this->getTitle(),
            'page_count' => $this->getPageCount(),
            'url' => $this->getUrl(),
            'cover' => $this->getCover(),
            'release_date' => $this->getReleaseDate()->format('Y-m-d'),
        ];
    }
}
