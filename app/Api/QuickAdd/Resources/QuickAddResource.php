<?php

namespace App\Api\QuickAdd\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuickAddResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'url' => $this['url'],
            'content_type' => $this['content_type'],
            'confidence' => $this['confidence'],
            'metadata' => $this['metadata'] ?? null,
        ];
    }
}
