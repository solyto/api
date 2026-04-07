<?php

namespace App\Api\Finances\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWealthFieldRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
        ];
    }
}
