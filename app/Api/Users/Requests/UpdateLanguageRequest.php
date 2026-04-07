<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLanguageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'language' => 'required|string',
        ];
    }
}
