<?php

namespace App\Api\Notes\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreNoteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'content' => 'nullable|string',
            'category_id' => 'nullable|exists:note_categories,id',
            'tags' => 'nullable|array',
            'tags.*' => 'exists:tags,id'
        ];
    }
}
