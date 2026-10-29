<?php

namespace App\Api\Finances\Resources;

use App\Api\Finances\Models\WealthField;
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
 *
 * @mixin WealthField
 */
class WealthFieldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'values' => $this->whenLoaded('values', fn () => WealthValueResource::collection($this->values->sortBy('date')->values())
            ),
            'currentValue' => $this->whenLoaded('values', fn () => $this->values->sortBy('date')->last()
                ? new WealthValueResource($this->values->sortBy('date')->last())
                : null
            ),
            'user_id' => $this->user_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
