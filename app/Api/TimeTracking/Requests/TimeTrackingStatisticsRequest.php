<?php

namespace App\Api\TimeTracking\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TimeTrackingStatisticsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'category_id' => 'nullable|integer|exists:time_tracking_categories,id',
            'category_ids' => ['nullable', 'string', 'regex:/^\d+(,\d+)*$/'],
        ];
    }
}
