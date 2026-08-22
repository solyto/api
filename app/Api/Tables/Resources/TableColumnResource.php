<?php

namespace App\Api\Tables\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="TableColumn",
 *
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="name", type="string"),
 *     @OA\Property(property="type", type="string", enum={"text", "number", "date", "checkbox", "url", "select", "tags", "picture"}),
 *     @OA\Property(property="options", type="array", @OA\Items(type="string"), nullable=true),
 *     @OA\Property(property="position", type="integer")
 * )
 */
class TableColumnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type instanceof \BackedEnum ? $this->type->value : $this->type,
            'options' => $this->options,
            'position' => $this->position,
        ];
    }
}
