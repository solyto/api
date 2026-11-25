<?php

namespace App\Api\CheckIn\Requests;

use App\Api\CheckIn\Enums\CheckInTrackerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetCheckInValuesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date' => 'required|date_format:Y-m-d',
            'values' => 'required|array|min:1|max:100',
            'values.*.tracker_id' => ['required', 'integer', 'distinct', Rule::exists('check_in_trackers', 'id')->where('user_id', $this->user()->id)],
            'values.*.value' => 'present|nullable|integer|min:0|max:'.CheckInTrackerType::MAX_COUNT,
        ];
    }
}
