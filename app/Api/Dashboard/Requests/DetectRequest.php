<?php

namespace App\Api\Dashboard\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DetectRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'content' => 'required|string|max:2048',
        ];
    }
}
