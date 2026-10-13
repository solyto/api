<?php

namespace App\Api\Libraries\Requests\Links;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'tags.*' => [Rule::exists('tags', 'id')->where('user_id', $this->user()->id)],
            'category_id' => ['sometimes', 'nullable', Rule::exists('library_links_categories', 'id')->where('user_id', $this->user()->id)],
        ];
    }
}
