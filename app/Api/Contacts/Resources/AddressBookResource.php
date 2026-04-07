<?php

namespace App\Api\Contacts\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="AddressBook",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="uri", type="string"),
 *     @OA\Property(property="name", type="string"),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="color", type="string")
 * )
 */
class AddressBookResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uri' => $this->uri,
            'name' => $this->displayName,
            'description' => $this->description,
            'color' => $this->color,
        ];
    }
}
