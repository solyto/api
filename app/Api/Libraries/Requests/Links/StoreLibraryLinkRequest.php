<?php

namespace App\Api\Libraries\Requests\Links;

use Illuminate\Foundation\Http\FormRequest;

class StoreLibraryLinkRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'nullable|string',
            'url' => 'required|url',
            'is_favorite' => 'boolean',
            'cover_path' => 'nullable|string',
            'tags' => 'nullable|array',
            'tags.*' => 'exists:tags,id',
        ];
    }
}
