<?php

namespace App\Api\Libraries\Requests\Links;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLibraryLinkRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|string',
            'url' => 'sometimes|url',
            'is_favorite' => 'sometimes|boolean',
            'cover_path' => 'nullable|string',
            'tags' => 'sometimes|nullable|array',
            'tags.*' => 'exists:tags,id',
            'category_id' => 'sometimes|nullable|exists:library_links_categories,id',
        ];
    }
}
