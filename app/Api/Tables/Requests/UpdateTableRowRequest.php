<?php

namespace App\Api\Tables\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTableRowRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'data' => 'nullable|array',
        ];
    }
}
