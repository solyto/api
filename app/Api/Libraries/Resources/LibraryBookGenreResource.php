<?php

namespace App\Api\Libraries\Resources;

use App\Api\Libraries\Models\LibraryBookGenre;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="LibraryBookGenre",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string")
 * )
 *
 * @mixin LibraryBookGenre
 */
class LibraryBookGenreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
        ];
    }
}
