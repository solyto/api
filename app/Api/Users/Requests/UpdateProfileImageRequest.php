<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileImageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'profile_image' => 'nullable|file|image|max:2048',
        ];
    }
}
