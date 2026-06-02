<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PasskeyRegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'response' => 'required|array',
            'name'     => 'sometimes|string|max:50',
        ];
    }
}
