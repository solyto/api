<?php

namespace App\Api\TimeTracking\Resources;

use App\Api\TimeTracking\Models\TimeTrackingProject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

/**
 * @OA\Schema(
 *     schema="TimeTrackingProject",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="color", type="string", nullable=true, description="Single project-colour authority, shared with the statistics endpoint."),
 *     @OA\Property(property="categories", type="array", @OA\Items(ref="#/components/schemas/TimeTrackingCategory"), nullable=true),
 *     @OA\Property(property="total_duration", type="integer", nullable=true, description="All-time total of the project's entry duration_minutes, summed in SQL (0 without entries); omitted when the project is nested in an entry; kept fresh on every entry mutation."),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @mixin TimeTrackingProject
 */
class TimeTrackingProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $totalDuration = $this->whenAggregated('entries', 'duration_minutes', 'sum');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'color' => $this->color,
            'categories' => $this->whenLoaded('categories', fn () => TimeTrackingCategoryResource::collection($this->categories)
            ),
            'total_duration' => $totalDuration instanceof MissingValue ? $totalDuration : (int) $totalDuration,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
