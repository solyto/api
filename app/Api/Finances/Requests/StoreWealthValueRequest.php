<?php

namespace App\Api\Finances\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWealthValueRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'value' => 'required|numeric',
        ];
    }
}
