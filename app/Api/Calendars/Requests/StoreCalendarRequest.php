<?php

namespace App\Api\Calendars\Requests;

use App\Api\Calendars\Services\CalendarService;
use Illuminate\Foundation\Http\FormRequest;

class StoreCalendarRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:'.CalendarService::MAX_NAME_LENGTH
        ];
    }
}
