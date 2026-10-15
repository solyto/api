<?php

namespace App\Api\Finances\Resources;

use App\Api\Finances\Models\Budget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="Budget",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="type", type="string", enum={"income","expense"}),
 *     @OA\Property(property="value", type="number", description="Current value: the value of the history entry with the latest date"),
 *     @OA\Property(property="values", type="array", @OA\Items(ref="#/components/schemas/BudgetValue"), description="Value history ordered by date, only when loaded"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @mixin Budget
 */
class BudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'value' => $this->value,
            'values' => $this->whenLoaded('values', fn () => BudgetValueResource::collection($this->values->sortBy('date')->values())
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
