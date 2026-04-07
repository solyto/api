<?php

namespace App\Api\DevRequests\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDevRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => 'required|string|in:feature,bug',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'screenshot' => 'nullable|string',
            'screenshot_name' => 'nullable|string|max:255',
            'priority' => 'nullable|integer|min:1|max:5',
            'url' => 'nullable|string|max:255|url',
            'created_by_user_id' => 'nullable|uuid|exists:users,id',
        ];
    }
}
