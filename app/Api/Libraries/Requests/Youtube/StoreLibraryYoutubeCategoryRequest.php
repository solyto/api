<?php

namespace App\Api\Libraries\Requests\Youtube;

use Illuminate\Foundation\Http\FormRequest;

class StoreLibraryYoutubeCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'color' => 'nullable|string|max:255',
        ];
    }
}
