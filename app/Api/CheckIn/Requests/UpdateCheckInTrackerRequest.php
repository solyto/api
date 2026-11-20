<?php

namespace App\Api\CheckIn\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCheckInTrackerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:50',
            'icon' => 'sometimes|nullable|string|max:64|regex:/^[a-z0-9-]+$/',
            'enabled' => 'sometimes|boolean',
            'scored' => 'sometimes|boolean',
        ];
    }
}
