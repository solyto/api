<?php

namespace App\Api\Contacts\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="ContactsImportState",
 *
 *     @OA\Property(property="stage", type="string"),
 *     @OA\Property(property="address_books", type="array", @OA\Items(type="string"), nullable=true),
 *     @OA\Property(property="address_books_count", type="integer"),
 *     @OA\Property(property="address_books_done", type="integer"),
 *     @OA\Property(property="address_books_current", type="string", nullable=true),
 *     @OA\Property(property="contacts_count", type="integer"),
 *     @OA\Property(property="contacts_done", type="integer")
 * )
 */
class ImportStateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'stage' => $this->stage,
            'address_books' => $this->addressBooks ? array_map(fn ($ab) => $ab['display_name'], $this->addressBooks) : null,
            'address_books_count' => $this->addressBooksCount,
            'address_books_done' => $this->addressBooksDone,
            'address_books_current' => $this->currentAddressBook,
            'contacts_count' => $this->contactsCount,
            'contacts_done' => $this->contactsDone,
        ];
    }
}
