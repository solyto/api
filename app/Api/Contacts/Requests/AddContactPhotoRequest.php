<?php

namespace App\Api\Contacts\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddContactPhotoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'photo' => 'required|string',
        ];
    }
}
