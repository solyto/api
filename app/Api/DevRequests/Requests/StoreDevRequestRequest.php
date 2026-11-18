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
            'description' => 'required|string|max:65535',
            // About 5 MB of image once decoded.
            'screenshot' => ['nullable', 'string', 'max:7000000', 'regex:/^data:image\/(png|jpeg|gif|webp);base64,[A-Za-z0-9+\/]+=*$/'],
            'screenshot_name' => 'nullable|string|max:255',
            'priority' => 'nullable|integer|min:1|max:5',
            'url' => 'nullable|string|max:255|url',
        ];
    }
}
