<?php

namespace App\Api\Calendars\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="CalendarImportState",
 *
 *     @OA\Property(property="stage", type="string"),
 *     @OA\Property(property="calendars", type="array", @OA\Items(type="string"), nullable=true),
 *     @OA\Property(property="calendars_count", type="integer"),
 *     @OA\Property(property="calendars_done", type="integer"),
 *     @OA\Property(property="calendars_current", type="string", nullable=true),
 *     @OA\Property(property="events_count", type="integer"),
 *     @OA\Property(property="events_done", type="integer")
 * )
 */
class ImportStateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'stage' => $this->stage,
            'calendars' => $this->calendars ? array_map(fn ($c) => $c['name'], $this->calendars) : null,
            'calendars_count' => $this->calendarsCount,
            'calendars_done' => $this->calendarsDone,
            'calendars_current' => $this->currentCalendar,
            'events_count' => $this->eventsCount,
            'events_done' => $this->eventsDone,
        ];
    }
}
