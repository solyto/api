<?php

namespace App\Api\Calendars\Resources;

use App\Api\Calendars\Models\ExternalCalendar;
use App\Api\Calendars\Models\ExternalCalendarAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="ExternalCalendarAccount",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="server_url", type="string"),
 *     @OA\Property(property="username", type="string"),
 *     @OA\Property(property="calendars", type="array", description="The connected calendars; id is the calendar id used by every calendars endpoint",
 *         @OA\Items(type="object",
 *             @OA\Property(property="id", type="integer"),
 *             @OA\Property(property="remote_url", type="string")
 *         )
 *     )
 * )
 *
 * @mixin ExternalCalendarAccount
 */
class ExternalCalendarAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'server_url' => $this->server_url,
            'username' => $this->username,
            'calendars' => $this->whenLoaded('calendars', fn () => $this->calendars->map(fn (ExternalCalendar $calendar) => [
                'id' => $calendar->instance_id,
                'remote_url' => $calendar->remote_url,
            ])->values()->all()),
        ];
    }
}
