<?php

namespace App\Api\Calendars\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExternalCalendarAccountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'username' => 'sometimes|string|max:255',
            'password' => 'required|string|max:1024',
        ];
    }
}
