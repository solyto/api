<?php

namespace App\Api\Libraries\Requests\Authors;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAuthorRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'bio' => 'nullable|string',
            'hardcover_id' => [
                'nullable',
                'integer',
                Rule::unique('authors')->where(fn ($query) => $query->where('user_id', $this->user()->id)),
            ],
            'is_favorite' => 'nullable|boolean',
        ];
    }
}
