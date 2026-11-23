<?php

namespace App\Api\Calendars\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExternalCalendarAccountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'username' => 'sometimes|string|max:255',
            // Every update is checked against the server, and the stored password may be the one it now rejects.
            'password' => 'required|string|max:1024',
        ];
    }
}
