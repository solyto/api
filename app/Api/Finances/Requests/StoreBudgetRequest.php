<?php

namespace App\Api\Finances\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBudgetRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'type' => 'required|string|in:income,expense',
            'interval' => 'sometimes|string|in:monthly,quarterly,yearly',
            'value' => 'required|numeric',
        ];
    }
}
