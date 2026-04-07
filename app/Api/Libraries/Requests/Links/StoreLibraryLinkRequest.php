<?php

namespace App\Api\Libraries\Requests\Links;

use Illuminate\Foundation\Http\FormRequest;

class StoreLibraryLinkRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'url' => 'required|url|max:255',
            'is_favorite' => 'boolean',
            'cover_path' => 'nullable|string|max:255',
            'tags' => 'nullable|array',
            'tags.*' => 'exists:tags,id',
        ];
    }
}
