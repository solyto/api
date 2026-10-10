<?php

namespace App\Api\Notes\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNoteCategoryHiddenRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'hidden' => 'required|boolean',
        ];
    }
}
