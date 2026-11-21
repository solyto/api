<?php

namespace App\Api\CheckIn\Resources;

use App\Api\CheckIn\Models\CheckInValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="CheckInValue",
 *
 *     @OA\Property(property="tracker_id", type="integer"),
 *     @OA\Property(property="date", type="string", format="date"),
 *     @OA\Property(property="value", type="integer")
 * )
 *
 * @mixin CheckInValue
 */
class CheckInValueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'tracker_id' => $this->check_in_tracker_id,
            'date' => $this->date->format('Y-m-d'),
            'value' => $this->value,
        ];
    }
}
