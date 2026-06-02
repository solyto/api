<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'token'    => 'required|string',
            'email'    => 'required|string|email',
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
