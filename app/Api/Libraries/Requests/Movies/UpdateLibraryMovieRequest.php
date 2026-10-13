<?php

namespace App\Api\Libraries\Requests\Movies;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'genres.*' => [Rule::exists('library_movies_genres', 'id')->where('user_id', $this->user()->id)],
            'tags' => 'sometimes|nullable|array',
            'tags.*' => [Rule::exists('tags', 'id')->where('user_id', $this->user()->id)],
        ];
    }
}
