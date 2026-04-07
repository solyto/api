<?php

namespace App\Api\Finances\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWealthFieldRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
        ];
    }
}
