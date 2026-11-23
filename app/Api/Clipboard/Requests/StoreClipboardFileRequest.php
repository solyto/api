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
                    $extension = strtolower($value->getClientOriginalExtension());
                    $allowedMimeTypes = config('clipboard.file_types')[$extension] ?? null;

                    if ($allowedMimeTypes === null || !in_array($value->getMimeType(), $allowedMimeTypes, true)) {
                        $fail('The file type is not allowed.');
                    }
                },
            ],
        ];
    }
}
