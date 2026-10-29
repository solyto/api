<?php

namespace App\Api\TimeTracking\Resources;

use App\Api\TimeTracking\Models\TimeTrackingEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="TimeTrackingEntry",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="started_at", type="string", format="date-time", description="Day anchor for the entry: when has_exact_times is false, its date is where the entry is placed and only the day is significant."),
 *     @OA\Property(property="stopped_at", type="string", format="date-time", nullable=true, description="Informational when has_exact_times is false; duration_minutes is the authoritative value."),
 *     @OA\Property(property="duration_minutes", type="integer", description="Authoritative duration in minutes, also for duration-only entries (has_exact_times = false)."),
 *     @OA\Property(property="has_exact_times", type="boolean", description="False for duration-only entries, true when exact start/stop times are recorded."),
 *     @OA\Property(property="project", ref="#/components/schemas/TimeTrackingProject", description="The project the entry belongs to. Entries are cascade-deleted with their project, so this is never null."),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @mixin TimeTrackingEntry
 */
class TimeTrackingEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'started_at' => $this->started_at,
            'stopped_at' => $this->stopped_at,
            'duration_minutes' => $this->duration_minutes,
            'has_exact_times' => $this->has_exact_times,
            'project' => $this->whenLoaded('project', fn () => new TimeTrackingProjectResource($this->project)
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
