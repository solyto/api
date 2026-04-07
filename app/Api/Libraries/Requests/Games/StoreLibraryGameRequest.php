<?php

namespace App\Api\Libraries\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;

class StoreLibraryGameRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'rating' => 'nullable|integer|min:1|max:5',
            'cover_path' => 'nullable|url|max:255',
            'link' => 'nullable|url|max:255',
            'started_at' => 'nullable|date',
            'finished_at' => 'nullable|date',
            'publication_year' => 'nullable|integer',
            'platform' => 'required|string|in:pc,playstation,xbox,nintendo,mobile,boardgame,other',
            'developer' => 'nullable|string|max:255',
            'publisher' => 'nullable|string|max:255',
            'playtime_hours' => 'nullable|integer|min:0',
            'completed' => 'nullable|boolean',
            'wishlist' => 'nullable|boolean',
            'genres' => 'nullable|array',
            'genres.*' => 'exists:library_games_genres,id',
            'tags' => 'nullable|array',
            'tags.*' => 'exists:tags,id',
        ];
    }
}
