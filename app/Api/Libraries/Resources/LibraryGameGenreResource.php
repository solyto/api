<?php

namespace App\Api\Libraries\Resources;

use App\Api\Libraries\Models\LibraryGameGenre;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="LibraryGameGenre",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string")
 * )
 *
 * @mixin LibraryGameGenre
 */
class LibraryGameGenreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
        ];
    }
}
