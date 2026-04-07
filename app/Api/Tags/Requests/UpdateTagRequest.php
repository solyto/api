<?php

namespace App\Api\Tags\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTagRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|string',
            'color' => 'sometimes|nullable|string|min:7|max:7',
        ];
    }
}
