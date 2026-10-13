<?php

namespace App\Api\TimeTracking\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTimeTrackingProjectRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string|max:1000',
            'color' => 'sometimes|nullable|string|max:7',
            'category_ids' => 'sometimes|nullable|array',
            'category_ids.*' => [Rule::exists('time_tracking_categories', 'id')->where('user_id', $this->user()->id)]
        ];
    }
}
