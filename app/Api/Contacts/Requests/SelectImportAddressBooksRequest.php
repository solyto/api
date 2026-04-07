<?php

namespace App\Api\Contacts\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SelectImportAddressBooksRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'address_books' => 'required|array',
            'address_books.*' => 'required|string',
        ];
    }
}
