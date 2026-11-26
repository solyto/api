<?php

namespace App\Api\Widgets\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddCheckInRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'value' => 'required|integer|in:-1,1',
        ];
    }
}
