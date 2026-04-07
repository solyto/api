<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user_id' => 'required|string',
            'token' => 'required|string',
        ];
    }
}
