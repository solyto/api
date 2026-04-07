<?php

namespace App\Api\Finances\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="WealthField",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="values", type="array", @OA\Items(ref="#/components/schemas/WealthValue"), nullable=true),
 *     @OA\Property(property="currentValue", ref="#/components/schemas/WealthValue", nullable=true),
 *     @OA\Property(property="user_id", type="integer"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class WealthFieldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'values' => $this->whenLoaded('values', fn () => WealthValueResource::collection($this->values)
            ),
            'currentValue' => $this->whenLoaded('values', fn () => $this->values->last()
                ? new WealthValueResource($this->values->last())
                : null
            ),
            'user_id' => $this->user_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
