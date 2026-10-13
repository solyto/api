<?php

namespace App\Api\Notes\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNoteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'content' => 'nullable|string',
            'category_id' => ['nullable', Rule::exists('note_categories', 'id')->where('user_id', $this->user()->id)],
            'tags' => 'nullable|array',
            'tags.*' => [Rule::exists('tags', 'id')->where('user_id', $this->user()->id)],
        ];
    }
}
