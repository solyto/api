<?php

namespace App\Api\Todos\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="TodoWorkspace",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="categories", type="array", @OA\Items(ref="#/components/schemas/TodoCategory"), nullable=true),
 *     @OA\Property(property="is_hideable", type="boolean"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class TodoWorkspaceResource extends JsonResource
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
            'title' => $this->title,
            'categories' => TodoCategoryResource::collection($this->whenLoaded('categories')),
            'is_hideable' => $this->is_hideable,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
