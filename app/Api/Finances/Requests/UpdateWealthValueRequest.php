<?php

namespace App\Api\Finances\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWealthValueRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'value' => 'sometimes|numeric',
            'date' => 'sometimes|date_format:Y-m-d|before_or_equal:today',
        ];
    }
}
