<?php

namespace App\Api\Libraries\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="LibraryMusicGenre",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string")
 * )
 */
class LibraryMusicGenreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
        ];
    }
}
