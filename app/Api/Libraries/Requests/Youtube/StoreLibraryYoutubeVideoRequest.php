<?php

namespace App\Api\Libraries\Requests\Youtube;

use Illuminate\Foundation\Http\FormRequest;

class StoreLibraryYoutubeVideoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'nullable|string',
            'url' => 'required|url',
            'is_favorite' => 'boolean',
            'cover_path' => 'nullable|string',
            'category_id' => 'nullable|exists:library_youtube_categories,id',
        ];
    }
}
