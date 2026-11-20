<?php

namespace App\Api\CheckIn\Requests;

use App\Api\CheckIn\Enums\CheckInTrackerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCheckInTrackerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:50',
            'type' => ['required', 'string', Rule::in(CheckInTrackerType::customValues())],
            'icon' => 'nullable|string|max:64|regex:/^[a-z0-9-]+$/',
        ];
    }
}
