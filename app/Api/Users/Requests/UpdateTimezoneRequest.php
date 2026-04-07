<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTimezoneRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'timezone' => 'required|string',
        ];
    }
}
