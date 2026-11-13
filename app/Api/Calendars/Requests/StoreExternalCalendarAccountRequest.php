<?php

namespace App\Api\Calendars\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExternalCalendarAccountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'server_url' => 'required|url:http,https|max:2048',
            'username' => 'required|string|max:255',
            'password' => 'required|string|max:1024',
        ];
    }
}
