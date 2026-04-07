<?php

namespace App\Api\Contacts\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GetContactPhotosRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'contacts' => 'required|array|max:10',
            'contacts.*.address_book_id' => 'required|integer',
            'contacts.*.uri' => 'required|string',
        ];
    }
}
