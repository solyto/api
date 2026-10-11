<?php

namespace App\Api\Planner\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="PlannerEntry",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="date", type="string", format="date"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="start_minutes", type="integer", description="Minutes since midnight (0-1439)."),
 *     @OA\Property(property="duration_minutes", type="integer"),
 *     @OA\Property(property="is_all_day", type="boolean"),
 *     @OA\Property(property="color", type="string", nullable=true),
 *     @OA\Property(property="todo_id", type="string", format="uuid", nullable=true),
 *     @OA\Property(property="sort_order", type="integer"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class PlannerEntryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date?->format('Y-m-d'),
            'title' => $this->title,
            'start_minutes' => $this->start_minutes,
            'duration_minutes' => $this->duration_minutes,
            'is_all_day' => $this->is_all_day,
            'color' => $this->color,
            'todo_id' => $this->todo_id,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
