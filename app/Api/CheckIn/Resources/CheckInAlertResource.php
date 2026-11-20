<?php

namespace App\Api\CheckIn\Resources;

use App\Api\CheckIn\Models\CheckInAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="CheckInAlert",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="time", type="string", example="20:00"),
 *     @OA\Property(property="target", type="integer", nullable=true, description="Only for count trackers: remind if the day's total is below this.")
 * )
 *
 * @mixin CheckInAlert
 */
class CheckInAlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'time' => substr((string) $this->time, 0, 5),
            'target' => $this->target,
        ];
    }
}
