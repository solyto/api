<?php

namespace App\Api\Notifications\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UnsubscribeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'endpoint' => 'required|string',
        ];
    }
}
