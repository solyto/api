<?php

namespace App\Api\Libraries\Requests\Music;

use Illuminate\Foundation\Http\FormRequest;

class StoreLibraryMusicRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'artist' => 'required|string|max:255',
            'type' => 'nullable|string|max:50',
            'format' => 'nullable|string|max:50',
            'condition' => 'nullable|string|max:50',
            'rating' => 'nullable|integer|min:1|max:5',
            'publication_year' => 'nullable|integer',
            'acquired_where' => 'nullable|string|max:255',
            'additional_info' => 'nullable|string',
            'cover_path' => 'nullable|url|max:255',
            'wishlist' => 'nullable|boolean',
            'link' => 'nullable|url|max:255',
            'genres' => 'nullable|array',
            'genres.*' => 'exists:library_music_genres,id'
        ];
    }
}
