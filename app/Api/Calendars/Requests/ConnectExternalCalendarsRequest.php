<?php

namespace App\Api\Calendars\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConnectExternalCalendarsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'urls' => 'required|array|min:1',
            'urls.*' => 'required|string|max:2048',
        ];
    }
}
