<?php

namespace App\Api\Notes\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNoteCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'parent_id' => ['nullable', Rule::exists('note_categories', 'id')->where('user_id', $this->user()->id)],
        ];
    }
}
