<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOpenaiApiKey extends FormRequest
{
    public function rules(): array
    {
        return [
            'key' => 'nullable|string|max:255',
        ];
    }
}
