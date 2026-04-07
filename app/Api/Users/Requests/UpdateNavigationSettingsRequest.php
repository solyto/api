<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNavigationSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'navigation' => 'required|json',
        ];
    }
}
