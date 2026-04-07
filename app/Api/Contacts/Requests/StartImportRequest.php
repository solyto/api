<?php

namespace App\Api\Contacts\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartImportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'url' => 'required|string|url',
            'username' => 'required|string',
            'secret' => 'required|string',
        ];
    }
}
