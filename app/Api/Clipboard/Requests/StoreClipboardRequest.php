<?php

namespace App\Api\Clipboard\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClipboardRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'content' => 'nullable|string',
        ];
    }
}
