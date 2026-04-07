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
    private const DEFAULT_TRACKERS = ['mood', 'sports', 'water', 'sleep', 'dreams', 'work', 'food_quality', 'food_amount'];

    private const DEFAULT_SPORTS = ['dumbbell', 'bike', 'mountain', 'footprints', 'waves_ladder'];

    public function toArray(Request $request): array
    {
        $settings = $this->check_in_settings;

        return [
            'enabled_trackers' => $settings['enabled_trackers'] ?? self::DEFAULT_TRACKERS,
            'selected_sports' => $settings['selected_sports'] ?? self::DEFAULT_SPORTS,
        ];
    }
}
