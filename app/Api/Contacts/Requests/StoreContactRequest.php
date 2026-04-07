<?php

namespace App\Api\Contacts\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'address_book_id' => 'required|integer',
            'full_name' => 'nullable|string|max:255',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'prefix' => 'nullable|string|max:50',
            'suffix' => 'nullable|string|max:50',
            'email' => 'nullable|json',
            'phone' => 'nullable|json',
            'groups' => 'nullable|json',
            'organization' => 'nullable|string|max:255',
            'title' => 'nullable|string|max:255',
            'note' => 'nullable|string',
            'photo' => 'nullable|string',
            'street' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:255',
        ];
    }
}
