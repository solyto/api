<?php

namespace App\Api\Todos\Resources;

use App\Api\Tags\Resources\TagResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="Todo",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="priority", type="string", enum={"low","medium","high"}),
 *     @OA\Property(property="due_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="is_completed", type="boolean"),
 *     @OA\Property(property="status", type="string", nullable=true),
 *     @OA\Property(property="effort", type="string", nullable=true),
 *     @OA\Property(property="progress", type="integer", nullable=true),
 *     @OA\Property(property="category", ref="#/components/schemas/TodoCategory", nullable=true),
 *     @OA\Property(property="tags", type="array", @OA\Items(ref="#/components/schemas/Tag"), nullable=true),
 *     @OA\Property(property="subtasks", type="array", @OA\Items(ref="#/components/schemas/TodoSubtask"), nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time"),
 *     @OA\Property(property="completed_at", type="string", format="date-time", nullable=true)
 * )
 */
class TodoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'due_at' => $this->due_at,
            'is_completed' => $this->is_completed,
            'status' => $this->status,
            'effort' => $this->effort,
            'progress' => $this->progress,
            'category' => $this->whenLoaded('category', fn () => new TodoCategoryResource($this->category)
            ),
            'tags' => $this->whenLoaded('tags', fn () => TagResource::collection($this->tags)
            ),
            'subtasks' => $this->whenLoaded('subtasks', fn () => TodoSubtaskResource::collection($this->subtasks)
            ),
            'recurrence_frequency' => $this->recurrence_frequency,
            'recurrence_interval' => $this->recurrence_interval,
            'recurrence_ends_at' => $this->recurrence_ends_at,
            'parent_task_id' => $this->parent_task_id,
            'auto_generated' => $this->auto_generated,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
