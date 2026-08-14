<?php

namespace App\Api\Todos\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTodoWorkspaceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:50',
            'is_hideable' => 'boolean',
        ];
    }
}
