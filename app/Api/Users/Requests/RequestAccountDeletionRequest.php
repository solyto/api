<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestAccountDeletionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'password' => 'required|string|current_password:sanctum',
            'platform' => 'sometimes|nullable|string|in:web,mobile,desktop',
        ];
    }

    public function messages(): array
    {
        return [
            'password.current_password' => 'The password is incorrect.',
        ];
    }
}
