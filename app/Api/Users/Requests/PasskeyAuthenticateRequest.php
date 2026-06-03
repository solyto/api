<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PasskeyAuthenticateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'response' => 'required|array',
            'platform' => 'nullable|string|in:web,mobile,desktop',
        ];
    }
}
