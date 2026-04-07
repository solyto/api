<?php

namespace App\Api\Libraries\Requests\Quotes;

use Illuminate\Foundation\Http\FormRequest;

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
            'tags.*' => 'exists:tags,id',
        ];
    }
}
