<?php

namespace App\Api\TimeTracking\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTimeTrackingProjectRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string|max:1000',
            'category_ids' => 'sometimes|nullable|array',
            'category_ids.*' => 'exists:time_tracking_categories,id'
        ];
    }
}
