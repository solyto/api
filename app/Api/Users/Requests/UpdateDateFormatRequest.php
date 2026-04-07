<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDateFormatRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date_format' => 'required|string|max:10',
        ];
    }
}
