<?php

namespace App\Api\Calendars\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEventRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'description' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'is_all_day' => 'required|boolean',
            'calendar_id' => 'required|integer',
            'is_recurring' => 'boolean',
            'recurrence_rule' => 'nullable|string|required_if:is_recurring,true',
            'recurrence_end' => 'nullable|date',
        ];
    }
}
