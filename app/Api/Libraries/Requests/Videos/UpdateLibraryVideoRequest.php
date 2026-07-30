<?php

namespace App\Api\Libraries\Requests\Videos;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLibraryVideoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|string',
            'url' => 'sometimes|url',
            'is_favorite' => 'sometimes|boolean',
            'cover_path' => 'nullable|string',
            'category_id' => 'sometimes|nullable|exists:library_videos_categories,id',
        ];
    }
}
