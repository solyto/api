<?php

namespace App\Api\Libraries\Requests\Movies;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLibraryMovieRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string',
            'rating' => 'sometimes|nullable|integer|min:1|max:5',
            'cover_path' => 'sometimes|nullable|url',
            'link' => 'sometimes|nullable|url',
            'started_at' => 'sometimes|nullable|date',
            'finished_at' => 'sometimes|nullable|date',
            'publication_year' => 'sometimes|nullable|integer',
            'category' => 'sometimes|string|in:movie,series',
            'wishlist' => 'sometimes|nullable|boolean',
            'genres' => 'sometimes|nullable|array',
            'genres.*' => 'exists:library_movies_genres,id',
            'tags' => 'sometimes|nullable|array',
            'tags.*' => 'exists:tags,id',
        ];
    }
}
