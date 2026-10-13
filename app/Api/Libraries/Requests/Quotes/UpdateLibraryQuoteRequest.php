<?php

namespace App\Api\Libraries\Requests\Quotes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLibraryQuoteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'summary' => 'sometimes|nullable|string|max:255',
            'author' => 'sometimes|nullable|string|max:255',
            'quote' => 'sometimes|required|string',
            'source' => 'sometimes|nullable|string|max:500',
            'tags' => 'sometimes|nullable|array',
            'tags.*' => [Rule::exists('tags', 'id')->where('user_id', $this->user()->id)],
        ];
    }
}
