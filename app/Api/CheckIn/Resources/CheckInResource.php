<?php

namespace App\Api\CheckIn\Resources;

use App\Api\CheckIn\DTOs\CheckInDayDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="CheckIn",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="date", type="string", format="date"),
 *     @OA\Property(property="mood", type="integer", nullable=true),
 *     @OA\Property(property="water", type="integer", nullable=true),
 *     @OA\Property(property="sports", type="string", nullable=true),
 *     @OA\Property(property="sleep", type="string", nullable=true),
 *     @OA\Property(property="dreams", type="string", nullable=true),
 *     @OA\Property(property="work", type="string", nullable=true),
 *     @OA\Property(property="food_quality", type="integer", nullable=true),
 *     @OA\Property(property="food_amount", type="integer", nullable=true),
 *     @OA\Property(property="menstruation", type="boolean", nullable=true),
 *     @OA\Property(property="alcohol", type="boolean", nullable=true),
 *     @OA\Property(property="smoking", type="boolean", nullable=true),
 *     @OA\Property(property="social_life", type="integer", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @property CheckInDayDTO $resource
 */
class CheckInResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id(),
            'date' => $this->resource->date,
            'mood' => $this->resource->values['mood'] ?? null,
            'water' => $this->resource->values['water'] ?? null,
            'sports' => $this->resource->values['sports'] ?? null,
            'sleep' => $this->resource->values['sleep'] ?? null,
            'dreams' => $this->resource->values['dreams'] ?? null,
            'work' => $this->resource->values['work'] ?? null,
            'food_quality' => $this->resource->values['food_quality'] ?? null,
            'food_amount' => $this->resource->values['food_amount'] ?? null,
            'menstruation' => $this->resource->values['menstruation'] ?? null,
            'alcohol' => $this->resource->values['alcohol'] ?? null,
            'smoking' => $this->resource->values['smoking'] ?? null,
            'social_life' => $this->resource->values['social_life'] ?? null,
            'created_at' => $this->resource->createdAt,
            'updated_at' => $this->resource->updatedAt,
        ];
    }
}
