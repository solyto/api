<?php

namespace App\Api\Users\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="Notification",
 *
 *     @OA\Property(property="id", type="string"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="body", type="string"),
 *     @OA\Property(property="link", type="string", nullable=true),
 *     @OA\Property(property="read_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time")
 * )
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'title'      => $this->data['title'] ?? 'Notification',
            'body'       => $this->data['body'] ?? '',
            'link'       => $this->data['link'] ?? null,
            'read_at'    => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
