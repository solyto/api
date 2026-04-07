<?php

namespace App\Api\Clipboard\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClipboardImageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'image' => 'required|file|image|mimes:jpeg,png,gif,webp|max:5120',
        ];
    }
}
