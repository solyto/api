<?php

namespace App\Api\Libraries\Requests\Videos;

use Illuminate\Foundation\Http\FormRequest;

class StoreLibraryVideoCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'color' => 'nullable|string|max:255',
        ];
    }
}
