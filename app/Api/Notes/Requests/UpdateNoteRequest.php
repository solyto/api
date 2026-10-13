<?php

namespace App\Api\Notes\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNoteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string',
            'content' => 'sometimes|string',
            'category_id' => ['sometimes', 'nullable', Rule::exists('note_categories', 'id')->where('user_id', $this->user()->id)],
            'tags' => 'sometimes|nullable|array',
            'tags.*' => [Rule::exists('tags', 'id')->where('user_id', $this->user()->id)],
            'is_favorite' => 'sometimes|boolean',
        ];
    }
}
