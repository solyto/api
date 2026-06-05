<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|string|email|max:255',
            'platform' => 'sometimes|nullable|string|in:web,mobile,desktop',
        ];
    }
}
