<?php

namespace App\Api\Libraries\Requests\Movies;

use Illuminate\Foundation\Http\FormRequest;

class StoreLibraryMovieRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'rating' => 'nullable|integer|min:1|max:5',
            'cover_path' => 'nullable|url',
            'link' => 'nullable|url',
            'started_at' => 'nullable|date',
            'finished_at' => 'nullable|date',
            'publication_year' => 'nullable|integer',
            'category' => 'string|in:movie,series',
            'wishlist' => 'nullable|boolean',
            'genres' => 'nullable|array',
            'genres.*' => 'exists:library_movies_genres,id',
            'tags' => 'nullable|array',
            'tags.*' => 'exists:tags,id',
        ];
    }
}
