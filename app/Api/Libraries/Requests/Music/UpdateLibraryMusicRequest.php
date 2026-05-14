<?php

namespace App\Api\Libraries\Requests\Music;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLibraryMusicRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|string',
            'artist' => 'sometimes|string',
            'type' => 'sometimes|nullable|string|max:50',
            'format' => 'sometimes|nullable|string|max:50',
            'condition' => 'sometimes|nullable|string|max:50',
            'rating' => 'sometimes|nullable|integer|min:1|max:5',
            'acquired_where' => 'sometimes|nullable|string',
            'publication_year' => 'nullable|integer',
            'additional_info' => 'sometimes|nullable|string',
            'cover_path' => 'sometimes|nullable|url|string',
            'wishlist' => 'sometimes|nullable|boolean',
            'link' => 'sometimes|nullable|url',
            'genres' => 'sometimes|nullable|array',
            'genres.*' => 'exists:library_music_genres,id'
        ];
    }
}
