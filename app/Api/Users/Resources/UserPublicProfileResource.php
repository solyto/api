<?php

namespace App\Api\Users\Resources;

use App\Api\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="UserPublicProfile",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="name", type="string")
 * )
 *
 * @mixin User
 */
class UserPublicProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
