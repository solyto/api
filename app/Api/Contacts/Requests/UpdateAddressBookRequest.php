<?php

namespace App\Api\Contacts\Requests;

use App\Api\Contacts\Services\ContactService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAddressBookRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Array form because the pattern contains "|" (Laravel splits pipe strings on it).
            'color' => ['sometimes', 'string', 'regex:'.ContactService::ADDRESS_BOOK_COLOR_PATTERN],
        ];
    }
}
