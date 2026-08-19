<?php

namespace App\Api\Libraries\Requests\Authors;

use Illuminate\Foundation\Http\FormRequest;

class UploadAuthorPhotoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:jpg,jpeg,png,gif,webp|max:1024',
        ];
    }
}
