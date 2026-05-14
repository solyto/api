<?php

namespace App\Api\Dashboard\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="QuickAddDetection",
 *
 *     @OA\Property(property="url", type="string", format="uri"),
 *     @OA\Property(property="content_type", type="string", enum={"music","books","movies","games","links","recipes","plants","quotes","todo","note","feed"}),
 *     @OA\Property(property="confidence", type="number", format="float", minimum=0, maximum=1),
 *     @OA\Property(property="metadata", type="object", nullable=true)
 * )
 */
class QuickAddResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->resource->url,
            'content_type' => $this->resource->contentType->value,
            'confidence' => $this->resource->confidence,
            'metadata' => $this->resource->metadata,
        ];
    }
}
