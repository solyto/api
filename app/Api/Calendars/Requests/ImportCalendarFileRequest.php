<?php

namespace App\Api\Calendars\Requests;

use App\Api\Calendars\Services\CalendarService;
use Illuminate\Foundation\Http\FormRequest;

class ImportCalendarFileRequest extends FormRequest
{
    private const int MAX_FILE_KILOBYTES = 10240;

    public function rules(): array
    {
        return [
            'file' => 'required|file|max:'.self::MAX_FILE_KILOBYTES.'|extensions:ics',
            'calendar_id' => 'nullable|integer|prohibits:name,color',
            'name' => 'nullable|string|max:'.CalendarService::MAX_NAME_LENGTH,
            'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/',
        ];
    }
}
