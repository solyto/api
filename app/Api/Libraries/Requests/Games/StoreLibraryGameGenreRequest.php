<?php

namespace App\Api\Libraries\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;

class StoreLibraryGameGenreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:50',
        ];
    }
}
