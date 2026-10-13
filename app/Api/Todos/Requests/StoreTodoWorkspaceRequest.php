<?php

namespace App\Api\Todos\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTodoWorkspaceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:50',
            'categories' => 'sometimes|array',
            'categories.*' => [Rule::exists('todo_categories', 'id')->where('user_id', $this->user()->id)],
        ];
    }
}
