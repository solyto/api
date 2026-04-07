<?php

namespace App\Api\DevRequests\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDevRequestCommentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'content' => 'required|string',
        ];
    }
}
