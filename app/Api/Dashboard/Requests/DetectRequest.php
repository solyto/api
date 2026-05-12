<?php

namespace App\Api\Dashboard\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DetectRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'url' => 'required|string|max:2048',
        ];
    }
}
