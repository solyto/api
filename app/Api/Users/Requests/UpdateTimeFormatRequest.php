<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTimeFormatRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'time_format' => 'required|string|max:10',
        ];
    }
}
