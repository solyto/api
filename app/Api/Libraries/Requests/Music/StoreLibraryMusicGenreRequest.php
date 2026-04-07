<?php

namespace App\Api\Libraries\Requests\Music;

use Illuminate\Foundation\Http\FormRequest;

class StoreLibraryMusicGenreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:50',
        ];
    }
}
