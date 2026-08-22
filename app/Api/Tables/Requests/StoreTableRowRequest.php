<?php

namespace App\Api\Tables\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTableRowRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'data' => 'nullable|array',
        ];
    }
}
