<?php

namespace App\Api\CheckIn\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListCheckInValuesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ];
    }
}
