<?php

namespace App\Api\DevRequests\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoteDevRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'vote' => 'present|nullable|in:up,down',
        ];
    }
}
