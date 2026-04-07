<?php

namespace App\Api\Libraries\Requests\Movies;

use Illuminate\Foundation\Http\FormRequest;

class StoreLibraryMovieGenreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:50',
        ];
    }
}
