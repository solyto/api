<?php

namespace App\Api\Calendars\Resources;

use App\Api\Users\Models\User;
use App\Dav\DTOs\EventDTO;
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
 *
 * @mixin EventDTO
 */
class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Times go out without an offset and clients read them as local time,
        // so timed events are shown in the user's zone, whatever zone a DAV
        // client stored them in. All-day dates are floating and stay as they are.
        $user = $request->user();
        $timezone = $user instanceof User ? ($user->settings->timezone ?? null) : null;
        $isAllDay = $this->isAllDay;
        $local = function (?\DateTimeInterface $date) use ($timezone, $isAllDay): ?string {
            if ($date && !$isAllDay && $timezone) {
                $date = \DateTime::createFromInterface($date)->setTimezone(new \DateTimeZone($timezone));
            }

            return $date?->format('Y-m-d\TH:i:s');
        };

        return [
            'id' => $this->id,
            'uri' => $this->uri,
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'start_date' => $local($this->startDate),
            'end_date' => $local($this->endDate),
            'is_all_day' => $isAllDay,
            'is_recurring' => $this->recurrenceRule !== null,
            'recurrence_rule' => $this->recurrenceRule,
            'recurrence_end' => $local($this->recurrenceEnd),
            'original_start_date' => $local($this->originalStartDate),
            'etag' => $this->etag,
            'calendar_id' => $this->calendarId,
            'calendar_name' => $this->calendarName,
            'calendar_color' => $this->calendarColor,
        ];
    }
}
