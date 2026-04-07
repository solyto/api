<?php

namespace App\Api\Feeds\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFeedSubscriptionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string',
            'whitelist' => 'sometimes|nullable|string',
            'blacklist' => 'sometimes|nullable|string',
        ];
    }
}
