<?php

namespace App\Api\Users\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="FriendRequest",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="status", type="string", enum={"pending","accepted","rejected"}),
 *     @OA\Property(property="direction", type="string", enum={"sent","received"}),
 *     @OA\Property(property="user", type="object",
 *         @OA\Property(property="id", type="integer"),
 *         @OA\Property(property="name", type="string"),
 *         @OA\Property(property="profile_image_path", type="string", format="uri", nullable=true)
 *     ),
 *     @OA\Property(property="created_at", type="string", format="date-time")
 * )
 */
class FriendRequestResource extends JsonResource
{
    public function toArray($request): array
    {
        $otherUser = $this->sender_id === $request->user()->id
            ? $this->receiver
            : $this->sender;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'direction' => $this->sender_id === $request->user()->id ? 'sent' : 'received',
            'user' => [
                'id' => $otherUser->id,
                'name' => $otherUser->name,
                'profile_image_path' => $otherUser->profile?->profile_image_path,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
