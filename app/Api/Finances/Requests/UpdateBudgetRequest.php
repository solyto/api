<?php

namespace App\Api\Finances\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBudgetRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'type' => 'sometimes|string|in:income,expense',
            'interval' => 'sometimes|string|in:monthly,quarterly,yearly',
            'value' => 'sometimes|numeric',
            'tags' => 'sometimes|nullable|array',
            'tags.*' => [Rule::exists('tags', 'id')->where('user_id', $this->user()->id)],
        ];
    }
}
