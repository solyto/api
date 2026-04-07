<?php

namespace App\Api\Libraries\Requests\Quotes;

use Illuminate\Foundation\Http\FormRequest;

class StoreLibraryQuoteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'summary' => 'nullable|string|max:255',
            'author' => 'nullable|string|max:255',
            'quote' => 'required|string',
            'source' => 'nullable|string|max:500',
            'tags' => 'nullable|array',
            'tags.*' => 'exists:tags,id',
        ];
    }
}
