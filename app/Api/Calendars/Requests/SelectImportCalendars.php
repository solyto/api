<?php

namespace App\Api\Calendars\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SelectImportCalendars extends FormRequest
{
    public function rules(): array
    {
        return [
            'calendars' => 'required|array',
        ];
    }
}
