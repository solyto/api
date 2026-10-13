<?php

namespace App\Api\Libraries\Requests\Music;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLibraryMusicRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'artist' => 'required|string',
            'type' => 'nullable|string|max:50',
            'format' => 'nullable|string|max:50',
            'condition' => 'nullable|string|max:50',
            'rating' => 'nullable|integer|min:1|max:5',
            'publication_year' => 'nullable|integer',
            'acquired_where' => 'nullable|string',
            'additional_info' => 'nullable|string',
            'cover_path' => 'nullable|url',
            'wishlist' => 'nullable|boolean',
            'link' => 'nullable|url',
            'genres' => 'nullable|array',
            'genres.*' => [Rule::exists('library_music_genres', 'id')->where('user_id', $this->user()->id)]
        ];
    }
}
