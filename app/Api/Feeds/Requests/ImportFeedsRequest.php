<?php

namespace App\Api\Feeds\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportFeedsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => 'required|file|max:1024|extensions:opml,xml',
        ];
    }
}
