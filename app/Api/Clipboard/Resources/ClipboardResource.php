<?php

namespace App\Api\Clipboard\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="Clipboard",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="content", type="string", nullable=true),
 *     @OA\Property(property="type", type="string", enum={"text","image"}),
 *     @OA\Property(property="file_path", type="string", format="uri", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class ClipboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'type' => $this->type,
            'file_path' => $this->file_path,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
