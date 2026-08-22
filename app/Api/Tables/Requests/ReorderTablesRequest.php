<?php

namespace App\Api\Tables\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderTablesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'ids' => 'required|array',
            'ids.*' => 'required|uuid|exists:tables,id',
        ];
    }
}
