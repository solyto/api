<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFriendRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'receiver_id' => 'required|exists:users,id',
        ];
    }
}
