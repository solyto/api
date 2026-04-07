<?php

namespace App\Api\Libraries\Requests\Books;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLibraryBookRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'author' => 'sometimes|required|string|max:255',
            'series' => 'sometimes|nullable|string|max:255',
            'volume' => 'sometimes|nullable|integer',
            'pages' => 'sometimes|nullable|integer',
            'current_page' => 'sometimes|nullable|integer',
            'rating' => 'sometimes|nullable|integer|min:1|max:5',
            'lent_to' => 'sometimes|nullable|string|max:255',
            'is_where' => 'sometimes|nullable|string',
            'cover_path' => 'sometimes|nullable|url|max:255',
            'link' => 'sometimes|nullable|url|max:255',
            'started_at' => 'sometimes|nullable|date',
            'finished_at' => 'sometimes|nullable|date',
            'publication_year' => 'nullable|integer',
            'wishlist' => 'sometimes|nullable|boolean',
            'summary' => 'sometimes|nullable|string',
            'genres' => 'sometimes|nullable|array',
            'genres.*' => 'exists:library_books_genres,id',
            'tags' => 'sometimes|nullable|array',
            'tags.*' => 'exists:tags,id'
        ];
    }
}
