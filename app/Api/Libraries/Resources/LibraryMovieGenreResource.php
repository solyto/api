<?php

namespace App\Api\Libraries\Resources;

use App\Api\Libraries\Models\LibraryMovieGenre;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="LibraryMovieGenre",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string")
 * )
 *
 * @mixin LibraryMovieGenre
 */
class LibraryMovieGenreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
        ];
    }
}
