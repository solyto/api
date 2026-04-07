<?php

namespace App\Api\Libraries\Requests\Links;

use Illuminate\Foundation\Http\FormRequest;

class StoreLibraryLinkCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'color' => 'nullable|string|max:255',
        ];
    }
}
