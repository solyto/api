<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmAccountDeletionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'token' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
        ];
    }
}
