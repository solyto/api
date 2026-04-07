<?php

namespace App\Api\Libraries\Requests\Books;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLibraryBookGenreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:50',
        ];
    }
}
