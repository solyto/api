<?php

namespace App\Api\TimeTracking\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="TimeTrackingEntry",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="started_at", type="string", format="date-time"),
 *     @OA\Property(property="stopped_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="duration_minutes", type="integer"),
 *     @OA\Property(property="project", ref="#/components/schemas/TimeTrackingProject", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
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
