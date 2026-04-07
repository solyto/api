<?php

namespace App\Api\Contacts\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAddressBookRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'color' => 'sometimes|string|max:255',
        ];
    }
}
