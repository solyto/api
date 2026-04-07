<?php

namespace App\Api\Shortcuts\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShortcutRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'url' => 'required|string|max:255|url',
            'order' => 'nullable|integer',
        ];
    }
}
