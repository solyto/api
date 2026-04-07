<?php

namespace App\Api\Notes\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNoteCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
        ];
    }
}
