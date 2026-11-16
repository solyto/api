<?php

namespace App\Api\Clipboard\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreClipboardFileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => [
                'bail',
                'required',
                'file',
                function (string $attribute, UploadedFile $value, Closure $fail) {
                    $allowed = config('clipboard.file_types')[strtolower($value->getClientOriginalExtension())] ?? null;

                    if ($allowed === null || !in_array($value->getMimeType(), $allowed, true)) {
                        $fail('The file type is not allowed.');
                    }
                },
            ],
        ];
    }
}
