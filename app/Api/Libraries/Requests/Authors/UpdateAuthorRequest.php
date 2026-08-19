<?php

namespace App\Api\Libraries\Requests\Authors;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAuthorRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'bio' => 'sometimes|nullable|string',
            'hardcover_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::unique('authors')
                    ->where(fn ($query) => $query->where('user_id', $this->user()->id))
                    ->ignore($this->route('author')),
            ],
            'is_favorite' => 'sometimes|nullable|boolean',
        ];
    }
}
