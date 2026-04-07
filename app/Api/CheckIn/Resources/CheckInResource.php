<?php

namespace App\Api\CheckIn\Resources;

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
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
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
            'id' => $this->id,
            'date' => $this->date?->format('Y-m-d'),
            'mood' => $this->mood,
            'water' => $this->water,
            'sports' => $this->sports,
            'sleep' => $this->sleep,
            'dreams' => $this->dreams,
            'work' => $this->work,
            'food_quality' => $this->food_quality,
            'food_amount' => $this->food_amount,
            'menstruation' => $this->menstruation,
            'alcohol' => $this->alcohol,
            'smoking' => $this->smoking,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
