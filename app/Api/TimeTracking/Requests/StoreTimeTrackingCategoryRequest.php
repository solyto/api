<?php

namespace App\Api\TimeTracking\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTimeTrackingCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'color' => 'nullable|string|max:7'
        ];
    }
}
