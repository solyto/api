<?php

namespace App\Api\TimeTracking\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTimeTrackingCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'color' => 'sometimes|nullable|string|max:7'
        ];
    }
}
