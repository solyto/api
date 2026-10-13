<?php

namespace App\Api\Libraries\Requests\Books;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLibraryBookRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'author' => 'required|string',
            'series' => 'nullable|string',
            'volume' => 'nullable|integer',
            'pages' => 'nullable|integer',
            'current_page' => 'nullable|integer',
            'rating' => 'nullable|integer|min:1|max:5',
            'lent_to' => 'nullable|string',
            'is_where' => 'nullable|string',
            'cover_path' => 'nullable|url',
            'link' => 'nullable|url',
            'started_at' => 'nullable|date',
            'finished_at' => 'nullable|date',
            'publication_year' => 'nullable|integer',
            'wishlist' => 'nullable|boolean',
            'summary' => 'nullable|string',
            'genres' => 'nullable|array',
            'genres.*' => [Rule::exists('library_books_genres', 'id')->where('user_id', $this->user()->id)],
            'tags' => 'nullable|array',
            'tags.*' => [Rule::exists('tags', 'id')->where('user_id', $this->user()->id)],
        ];
    }
}
