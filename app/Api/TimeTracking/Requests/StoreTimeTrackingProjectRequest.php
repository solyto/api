<?php

namespace App\Api\TimeTracking\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTimeTrackingProjectRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'color' => 'nullable|string|max:7',
            'category_ids' => 'nullable|array',
            'category_ids.*' => [Rule::exists('time_tracking_categories', 'id')->where('user_id', $this->user()->id)]
        ];
    }
}
