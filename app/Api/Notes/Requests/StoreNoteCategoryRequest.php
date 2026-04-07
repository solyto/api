<?php

namespace App\Api\Notes\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreNoteCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'parent_id' => 'nullable|exists:note_categories,id',
        ];
    }
}
