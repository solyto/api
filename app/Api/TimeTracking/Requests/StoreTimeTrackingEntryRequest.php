<?php

namespace App\Api\TimeTracking\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTimeTrackingEntryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'description' => 'nullable|string|max:255',
            'started_at' => 'required|date',
            'stopped_at' => 'required|date|after_or_equal:started_at',
            'duration_minutes' => 'required|integer|min:1',
            'has_exact_times' => 'boolean',
            'project_id' => 'required|exists:time_tracking_projects,id'
        ];
    }
}
