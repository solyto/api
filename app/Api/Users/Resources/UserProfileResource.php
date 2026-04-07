<?php

namespace App\Api\Users\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="UserProfile",
 *
 *     @OA\Property(property="profile_image_path", type="string", nullable=true)
 * )
 */
class UserProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'profile_image_path' => $this->profile_image_path,
        ];
    }
}
