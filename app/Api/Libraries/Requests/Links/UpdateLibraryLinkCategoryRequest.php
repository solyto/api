<?php

namespace App\Api\Libraries\Requests\Links;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLibraryLinkCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'color' => 'sometimes|string|max:255',
        ];
    }
}
