<?php

namespace App\Api\Libraries\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="LibraryPlant",
 *
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="name", type="string"),
 *     @OA\Property(property="latin_name", type="string", nullable=true),
 *     @OA\Property(property="location", type="string", enum={"indoor", "outdoor", "both"}, nullable=true),
 *     @OA\Property(property="sunlight", type="string", enum={"full_sun", "partial_sun", "indirect", "shade"}, nullable=true),
 *     @OA\Property(property="current_size", type="string", nullable=true),
 *     @OA\Property(property="max_size", type="string", nullable=true),
 *     @OA\Property(property="acquired_at", type="string", format="date", nullable=true),
 *     @OA\Property(property="winter_hardy", type="boolean", nullable=true),
 *     @OA\Property(property="instructions", type="string", nullable=true),
 *     @OA\Property(property="cover", type="string", nullable=true),
 *     @OA\Property(property="link", type="string", format="uri", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class LibraryPlantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'latin_name' => $this->latin_name,
            'location' => $this->location,
            'sunlight' => $this->sunlight,
            'current_size' => $this->current_size,
            'max_size' => $this->max_size,
            'acquired_at' => $this->acquired_at,
            'winter_hardy' => $this->winter_hardy,
            'instructions' => $this->instructions,
            'cover' => $this->cover_path,
            'link' => $this->link,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
