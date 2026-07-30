<?php

namespace App\Api\Libraries\Requests\Youtube;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLibraryYoutubeCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'color' => 'sometimes|string|max:255',
        ];
    }
}
