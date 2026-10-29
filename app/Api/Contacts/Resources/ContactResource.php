<?php

namespace App\Api\Contacts\Resources;

use App\Dav\DTOs\ContactDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="Contact",
 *
 *     @OA\Property(property="uid", type="string"),
 *     @OA\Property(property="uri", type="string"),
 *     @OA\Property(property="address_book_id", type="integer"),
 *     @OA\Property(property="address_book_color", type="string"),
 *     @OA\Property(property="full_name", type="string"),
 *     @OA\Property(property="first_name", type="string"),
 *     @OA\Property(property="last_name", type="string"),
 *     @OA\Property(property="middle_name", type="string"),
 *     @OA\Property(property="prefix", type="string"),
 *     @OA\Property(property="suffix", type="string"),
 *     @OA\Property(property="email", type="array", @OA\Items(type="string"), nullable=true),
 *     @OA\Property(property="phone", type="array", @OA\Items(type="string"), nullable=true),
 *     @OA\Property(property="groups", type="array", @OA\Items(type="string"), nullable=true),
 *     @OA\Property(property="organization", type="string", nullable=true),
 *     @OA\Property(property="note", type="string", nullable=true),
 *     @OA\Property(property="street", type="string", nullable=true),
 *     @OA\Property(property="city", type="string", nullable=true),
 *     @OA\Property(property="state", type="string", nullable=true),
 *     @OA\Property(property="postal_code", type="string", nullable=true),
 *     @OA\Property(property="country", type="string", nullable=true),
 *     @OA\Property(property="etag", type="string")
 * )
 *
 * @mixin ContactDTO
 */
class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uid' => $this->uid,
            'uri' => $this->uri,
            'address_book_id' => $this->addressBookId,
            'address_book_color' => $this->addressBookColor,
            'full_name' => $this->fullName,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'middle_name' => $this->middleName,
            'prefix' => $this->prefix,
            'suffix' => $this->suffix,
            'email' => $this->decodeJson($this->email),
            'phone' => $this->decodeJson($this->phone),
            'groups' => $this->decodeJson($this->groups),
            'organization' => $this->organization,
            'note' => $this->note,
            'street' => $this->street,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
            'etag' => $this->etag,
        ];
    }

    private function decodeJson(?string $value): ?array
    {
        if (! $value) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [$value];
    }
}
