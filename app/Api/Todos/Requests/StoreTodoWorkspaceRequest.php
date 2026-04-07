<?php

namespace App\Api\Todos\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTodoWorkspaceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:50',
            'categories' => 'sometimes|array',
            'categories.*' => 'exists:todo_categories,id'
        ];
    }
}
