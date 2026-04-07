<?php

namespace App\Api\Finances\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBudgetRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'type' => 'sometimes|string|in:income,expense',
            'value' => 'sometimes|numeric',
        ];
    }
}
