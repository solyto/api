<?php

namespace App\Api\Tables\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="Table",
 *
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="name", type="string"),
 *     @OA\Property(property="icon", type="string", nullable=true),
 *     @OA\Property(property="view", type="string", enum={"list", "card"}),
 *     @OA\Property(property="position", type="integer"),
 *     @OA\Property(property="rows_count", type="integer"),
 *     @OA\Property(property="columns", type="array", @OA\Items(ref="#/components/schemas/TableColumn"), nullable=true),
 *     @OA\Property(property="rows", type="array", @OA\Items(ref="#/components/schemas/TableRow"), nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class TableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon' => $this->icon,
            'view' => $this->view,
            'position' => $this->position,
            'rows_count' => $this->whenCounted('rows'),
            'columns' => TableColumnResource::collection($this->whenLoaded('columns')),
            'rows' => TableRowResource::collection($this->whenLoaded('rows')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
