<?php

namespace App\Api\Calendars\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="Event",
 *
 *     @OA\Property(property="id", type="string"),
 *     @OA\Property(property="uri", type="string"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="location", type="string", nullable=true),
 *     @OA\Property(property="start_date", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="end_date", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="is_all_day", type="boolean"),
 *     @OA\Property(property="is_recurring", type="boolean"),
 *     @OA\Property(property="recurrence_rule", type="string", nullable=true),
 *     @OA\Property(property="recurrence_end", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="original_start_date", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="etag", type="string"),
 *     @OA\Property(property="calendar_id", type="integer"),
 *     @OA\Property(property="calendar_name", type="string"),
 *     @OA\Property(property="calendar_color", type="string")
 * )
 */
class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uri' => $this->uri,
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'start_date' => $this->startDate?->format('Y-m-d\TH:i:s'),
            'end_date' => $this->endDate?->format('Y-m-d\TH:i:s'),
            'is_all_day' => $this->isAllDay,
            'is_recurring' => $this->recurrenceRule !== null,
            'recurrence_rule' => $this->recurrenceRule,
            'recurrence_end' => $this->recurrenceEnd?->format('Y-m-d\TH:i:s'),
            'original_start_date' => $this->originalStartDate?->format('Y-m-d\TH:i:s'),
            'etag' => $this->etag,
            'calendar_id' => $this->calendarId,
            'calendar_name' => $this->calendarName,
            'calendar_color' => $this->calendarColor,
        ];
    }
}
