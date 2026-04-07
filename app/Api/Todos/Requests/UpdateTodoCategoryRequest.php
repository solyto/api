<?php

namespace App\Api\Todos\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTodoCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
        ];
    }
}
