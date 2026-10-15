<?php

namespace App\Api\Finances\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBudgetValueRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'value' => 'required|numeric',
            'date' => 'sometimes|date_format:Y-m-d|before_or_equal:today',
        ];
    }
}
