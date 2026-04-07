<?php

namespace App\Api\TimeTracking\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="TimeTrackingProject",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="categories", type="array", @OA\Items(ref="#/components/schemas/TimeTrackingCategory"), nullable=true),
 *     @OA\Property(property="total_duration", type="integer", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class TimeTrackingProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'categories' => $this->whenLoaded('categories', fn () => TimeTrackingCategoryResource::collection($this->categories)
            ),
            'total_duration' => $this->whenLoaded('entries', fn () => $this->entries->sum('duration_minutes')
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
