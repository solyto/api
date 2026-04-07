<?php

namespace App\Api\Calendars\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCalendarRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'color' => 'sometimes|string|max:255'
        ];
    }
}
