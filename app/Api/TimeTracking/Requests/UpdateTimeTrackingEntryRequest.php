<?php

namespace App\Api\TimeTracking\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTimeTrackingEntryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'description' => 'sometimes|nullable|string|max:255',
            'started_at' => 'sometimes|date',
            'stopped_at' => 'sometimes|nullable|date',
            'duration_minutes' => 'sometimes|integer|min:0',
            'has_exact_times' => 'sometimes|boolean',
            'project_id' => 'sometimes|exists:time_tracking_projects,id',
        ];
    }
}
