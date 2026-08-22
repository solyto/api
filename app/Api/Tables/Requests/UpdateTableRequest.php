<?php

namespace App\Api\Tables\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTableRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'icon' => 'nullable|string|max:255',
            'view' => 'sometimes|required|string|in:list,card',
        ];
    }
}
