<?php

namespace App\Api\Notes\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNoteFavoriteStatusRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'is_favorite' => 'required|boolean',
        ];
    }
}
