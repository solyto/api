<?php

namespace App\Api\CheckIn\Requests;

use App\Api\CheckIn\Enums\CheckInTrackerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetCheckInValueRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tracker_id' => ['required', 'integer', Rule::exists('check_in_trackers', 'id')->where('user_id', $this->user()->id)],
            'date' => 'required|date_format:Y-m-d',
            'value' => 'present|nullable|integer|min:0|max:'.CheckInTrackerType::MAX_COUNT,
        ];
    }
}
