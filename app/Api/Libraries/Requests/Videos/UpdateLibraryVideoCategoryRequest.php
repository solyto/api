<?php

namespace App\Api\Libraries\Requests\Videos;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLibraryVideoCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'color' => 'sometimes|string|max:255',
        ];
    }
}
