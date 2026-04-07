<?php

namespace App\Api\Shortcuts\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShortcutRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'url' => 'sometimes|required|string|max:255|url',
            'order' => 'nullable|integer',
        ];
    }
}
