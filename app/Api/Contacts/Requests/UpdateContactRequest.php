<?php

namespace App\Api\Contacts\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContactRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'address_book_id' => 'required|integer',
            'full_name' => 'sometimes|nullable|string|max:255',
            'first_name' => 'sometimes|nullable|string|max:255',
            'last_name' => 'sometimes|nullable|string|max:255',
            'middle_name' => 'sometimes|nullable|string|max:255',
            'prefix' => 'sometimes|nullable|string|max:50',
            'suffix' => 'sometimes|nullable|string|max:50',
            'email' => 'sometimes|nullable|json',
            'phone' => 'sometimes|nullable|json',
            'groups' => 'sometimes|nullable|json',
            'organization' => 'sometimes|nullable|string|max:255',
            'title' => 'sometimes|nullable|string|max:255',
            'note' => 'sometimes|nullable|string',
            'photo' => 'sometimes|nullable|string',
            'street' => 'sometimes|nullable|string|max:255',
            'city' => 'sometimes|nullable|string|max:255',
            'state' => 'sometimes|nullable|string|max:255',
            'postal_code' => 'sometimes|nullable|string|max:20',
            'country' => 'sometimes|nullable|string|max:255',
        ];
    }
}
