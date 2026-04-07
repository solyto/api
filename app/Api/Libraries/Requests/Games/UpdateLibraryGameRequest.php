<?php

namespace App\Api\Libraries\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLibraryGameRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'rating' => 'sometimes|nullable|integer|min:1|max:5',
            'cover_path' => 'sometimes|nullable|url|max:255',
            'link' => 'sometimes|nullable|url|max:255',
            'started_at' => 'sometimes|nullable|date',
            'finished_at' => 'sometimes|nullable|date',
            'publication_year' => 'sometimes|nullable|integer',
            'platform' => 'sometimes|required|string|in:pc,playstation,xbox,nintendo,mobile,boardgame,other',
            'developer' => 'sometimes|nullable|string|max:255',
            'publisher' => 'sometimes|nullable|string|max:255',
            'playtime_hours' => 'sometimes|nullable|integer|min:0',
            'completed' => 'sometimes|nullable|boolean',
            'wishlist' => 'sometimes|nullable|boolean',
            'genres' => 'sometimes|nullable|array',
            'genres.*' => 'exists:library_games_genres,id',
            'tags' => 'sometimes|nullable|array',
            'tags.*' => 'exists:tags,id',
        ];
    }
}
