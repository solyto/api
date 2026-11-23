<?php

namespace App\Api\TimeTracking\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartTimeTrackingTimerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'project_id' => 'required|exists:time_tracking_projects,id',
            'description' => 'nullable|string|max:255',
        ];
    }
}
