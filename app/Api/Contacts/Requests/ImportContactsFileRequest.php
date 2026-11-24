<?php

namespace App\Api\Contacts\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportContactsFileRequest extends FormRequest
{
    private const int MAX_FILE_KILOBYTES = 25600;

    public function rules(): array
    {
        return [
            'file' => 'required|file|max:'.self::MAX_FILE_KILOBYTES.'|extensions:vcf,vcard',
            'address_book_id' => 'nullable|integer|prohibits:name,color',
            'name' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:255',
        ];
    }
}
