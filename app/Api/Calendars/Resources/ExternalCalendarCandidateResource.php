<?php

namespace App\Api\Calendars\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="ExternalCalendarCandidate",
 *
 *     @OA\Property(property="url", type="string", description="Send this to connect the calendar"),
 *     @OA\Property(property="name", type="string"),
 *     @OA\Property(property="color", type="string"),
 *     @OA\Property(property="is_connected", type="boolean")
 * )
 */
class ExternalCalendarCandidateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'url' => $this['url'],
            'name' => $this['name'],
            'color' => $this['color'],
            'is_connected' => $this['is_connected'],
        ];
    }
}
