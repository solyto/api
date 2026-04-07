<?php

namespace App\Api\Libraries\Requests\Movies;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLibraryMovieGenreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:50',
        ];
    }
}
