<?php

namespace App\Api\Todos\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTodoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string|max:1000',
            'link' => 'sometimes|nullable|string|max:2048',
            'priority' => 'sometimes|in:low,medium,high',
            'due_at' => 'sometimes|nullable|string',
            'status' => 'sometimes|in:backlog,pending,in-progress,waiting,almost-done',
            'progress' => 'sometimes|nullable|integer|min:0|max:100',
            'effort' => 'sometimes|nullable|in:low,medium,high',
            'is_completed' => 'sometimes|boolean',
            'category_id' => ['sometimes', 'nullable', Rule::exists('todo_categories', 'id')->where('user_id', $this->user()->id)],
            'tags' => 'sometimes|nullable|array',
            'tags.*' => [Rule::exists('tags', 'id')->where('user_id', $this->user()->id)],
            'recurrence_frequency' => 'sometimes|nullable|in:daily,weekly,monthly,yearly',
            'recurrence_interval' => 'sometimes|integer|min:1|max:365',
            'recurrence_ends_at' => 'sometimes|nullable|string',
        ];
    }

    public function attributes(): array
    {
        return [
            'due_at' => 'due date',
            'is_completed' => 'completion status',
        ];
    }
}
