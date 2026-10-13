<?php

namespace App\Api\Libraries\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLibraryGameRequest extends FormRequest
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
            'platform' => 'required|string|in:pc,playstation,xbox,nintendo,mobile,boardgame,other',
            'developer' => 'nullable|string',
            'publisher' => 'nullable|string',
            'playtime_hours' => 'nullable|integer|min:0',
            'completed' => 'nullable|boolean',
            'wishlist' => 'nullable|boolean',
            'genres' => 'nullable|array',
            'genres.*' => [Rule::exists('library_games_genres', 'id')->where('user_id', $this->user()->id)],
            'tags' => 'nullable|array',
            'tags.*' => [Rule::exists('tags', 'id')->where('user_id', $this->user()->id)],
        ];
    }
}
