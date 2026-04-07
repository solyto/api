<?php

namespace App\Api\Calendars\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartImportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'url' => 'required|url|max:255',
            'username' => 'required|string|max:255',
            'secret' => 'required|string|max:255'
        ];
    }
}
