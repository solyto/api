<?php

namespace App\Api\Dashboard\Requests;

use App\Api\Dashboard\Enums\QuickAddContentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CommitRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'url' => 'required|string|max:2048',
            'content_type' => ['required', 'string', Rule::enum(QuickAddContentType::class)],
            'metadata' => 'sometimes|nullable|array',
        ];
    }
}
