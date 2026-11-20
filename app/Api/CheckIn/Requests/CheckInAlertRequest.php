<?php

namespace App\Api\CheckIn\Requests;

use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Models\CheckInAlert;
use App\Api\CheckIn\Models\CheckInTracker;
use Illuminate\Foundation\Http\FormRequest;

class CheckInAlertRequest extends FormRequest
{
    public function rules(): array
    {
        $alert = $this->route('alert');
        /** @var CheckInTracker $tracker */
        $tracker = $alert instanceof CheckInAlert ? $alert->tracker : $this->route('tracker');

        return [
            'time' => 'required|date_format:H:i',
            'target' => $tracker->type === CheckInTrackerType::Count
                ? 'required|integer|min:1|max:'.CheckInTrackerType::MAX_COUNT
                : 'prohibited',
        ];
    }
}
