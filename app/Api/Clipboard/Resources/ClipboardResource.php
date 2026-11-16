<?php

namespace App\Api\Clipboard\Resources;

use App\Api\Clipboard\Models\Clipboard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="Clipboard",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="content", type="string", nullable=true),
 *     @OA\Property(property="type", type="string", enum={"text","image","file"}),
 *     @OA\Property(property="file_path", type="string", format="uri", nullable=true, description="Images only; deprecated, load the image through GET /clipboard/{id}/image"),
 *     @OA\Property(property="file_name", type="string", nullable=true),
 *     @OA\Property(property="file_size", type="integer", nullable=true, description="Bytes"),
 *     @OA\Property(property="mime_type", type="string", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @mixin Clipboard
 */
class ClipboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'type' => $this->type,
            // Images are served through GET clipboard/{id}/image now; builds up to 1.1.3 still need a non-null file_path to render an entry as an image.
            'file_path' => $this->type === 'file' ? null : $this->file_path,
            'file_name' => $this->file_name,
            'file_size' => $this->file_size,
            'mime_type' => $this->mime_type,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
