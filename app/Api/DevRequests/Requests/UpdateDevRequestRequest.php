<?php

namespace App\Api\DevRequests\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDevRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => 'sometimes|required|string|in:feature,bug',
            'status' => 'sometimes|required|string|in:backlog,pending,in-progress,completed,cancelled',
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'screenshot' => 'sometimes|nullable|string|max:255',
            'priority' => 'sometimes|nullable|integer|min:1|max:5',
            'url' => 'sometimes|nullable|string|max:255|url',
        ];
    }
}
