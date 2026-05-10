<?php

namespace App\Api\QuickAdd\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DetectRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'url' => 'required|url|max:2048',
        ];
    }
}
