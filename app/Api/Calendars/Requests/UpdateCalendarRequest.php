<?php

namespace App\Api\Calendars\Requests;

use App\Api\Calendars\Services\CalendarService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCalendarRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Array form because the pattern contains "|" (Laravel splits pipe strings on it).
            'color' => ['sometimes', 'string', 'regex:'.CalendarService::COLOR_PATTERN],
        ];
    }
}
