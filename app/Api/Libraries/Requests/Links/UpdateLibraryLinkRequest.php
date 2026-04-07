<?php

namespace App\Api\Libraries\Requests\Links;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLibraryLinkRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'url' => 'sometimes|url|max:255',
            'is_favorite' => 'sometimes|boolean',
            'cover_path' => 'nullable|string|max:255',
            'tags' => 'sometimes|nullable|array',
            'tags.*' => 'exists:tags,id',
            'category_id' => 'sometimes|nullable|exists:library_links_categories,id',
        ];
    }
}
