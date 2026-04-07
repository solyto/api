<?php

namespace App\Api\TimeTracking\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTimeTrackingProjectRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'exists:time_tracking_categories,id'
        ];
    }
}
