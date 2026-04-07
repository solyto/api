<?php

namespace App\Api\Notifications\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubscribeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'endpoint' => 'required|string',
            'keys' => 'required|array',
            'keys.auth' => 'required|string',
            'keys.p256dh' => 'required|string',
        ];
    }
}
