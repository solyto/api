<?php

namespace App\Api\Tables\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTableRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'icon' => 'nullable|string|max:255',
            'view' => 'nullable|string|in:list,card',
        ];
    }
}
