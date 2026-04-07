<?php

namespace App\Api\Tags\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTagRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:50',
            'color' => 'sometimes|nullable|string|min:7|max:7',
        ];
    }
}
