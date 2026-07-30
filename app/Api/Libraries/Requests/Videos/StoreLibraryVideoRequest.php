<?php

namespace App\Api\Libraries\Requests\Videos;

use Illuminate\Foundation\Http\FormRequest;

class StoreLibraryVideoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'nullable|string',
            'url' => 'required|url',
            'is_favorite' => 'boolean',
            'cover_path' => 'nullable|string',
            'category_id' => 'nullable|exists:library_videos_categories,id',
        ];
    }
}
