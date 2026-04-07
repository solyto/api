<?php

namespace App\Api\Feeds\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFeedSubscriptionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'url' => 'required|string|url',
            'whitelist' => 'nullable|string',
            'blacklist' => 'nullable|string',
        ];
    }
}
