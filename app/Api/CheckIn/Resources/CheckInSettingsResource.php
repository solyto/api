<?php

namespace App\Api\CheckIn\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="CheckInSettings",
 *
 *     @OA\Property(property="enabled_trackers", type="array", @OA\Items(type="string")),
 *     @OA\Property(property="selected_sports", type="array", @OA\Items(type="string"))
 * )
 */
class CheckInSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'enabled_trackers' => $this->resource['enabled_trackers'],
            'selected_sports' => $this->resource['selected_sports'],
        ];
    }
}
