<?php

namespace App\Api\Contacts\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddContactPhotoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'photo' => 'required|file|image|mimes:jpeg,png,gif,webp|max:10240',
        ];
    }
}
