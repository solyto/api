<?php

namespace App\Api\Todos\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTodoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'link' => 'nullable|string|max:2048',
            'priority' => 'sometimes|in:low,medium,high',
            'due_at' => 'nullable|string',
            'status' => 'sometimes|in:backlog,pending,in-progress,waiting,almost-done',
            'progress' => 'sometimes|nullable|integer|min:0|max:100',
            'effort' => 'sometimes|nullable|in:low,medium,high',
            'category_id' => 'nullable|exists:todo_categories,id',
            'tags' => 'nullable|array',
            'tags.*' => 'exists:tags,id',
            'recurrence_frequency' => 'nullable|in:daily,weekly,monthly,yearly',
            'recurrence_interval' => 'sometimes|integer|min:1|max:365',
            'recurrence_ends_at' => 'nullable|string',
        ];
    }

    public function attributes(): array
    {
        return [
            'due_at' => 'due date',
        ];
    }
}
