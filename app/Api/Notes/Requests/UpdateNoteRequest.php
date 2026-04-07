<?php

namespace App\Api\Notes\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNoteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string',
            'content' => 'sometimes|string',
            'category_id' => 'sometimes|nullable|exists:note_categories,id',
            'tags' => 'sometimes|nullable|array',
            'tags.*' => 'exists:tags,id',
            'is_favorite' => 'sometimes|boolean',
        ];
    }
}
