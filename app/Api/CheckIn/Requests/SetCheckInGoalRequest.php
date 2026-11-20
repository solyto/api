<?php

namespace App\Api\CheckIn\Requests;

use App\Api\CheckIn\Enums\CheckInGoalComparison;
use App\Api\CheckIn\Enums\CheckInGoalPeriod;
use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Models\CheckInTracker;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetCheckInGoalRequest extends FormRequest
{
    private const array DAYS_IN_PERIOD = ['day' => 1, 'week' => 7, 'month' => 31];

    public function rules(): array
    {
        /** @var CheckInTracker $tracker */
        $tracker = $this->route('tracker');
        $period = $this->input('period');

        $targetRange = match ($tracker->type) {
            CheckInTrackerType::Scale => ['min:1', 'max:5'],
            CheckInTrackerType::YesNo, CheckInTrackerType::Sport => ['integer', 'min:0', 'max:'.(self::DAYS_IN_PERIOD[$period] ?? 31)],
            CheckInTrackerType::Count => ['integer', 'min:0', 'max:'.CheckInTrackerType::MAX_COUNT],
        };

        return [
            'period' => ['required', Rule::enum(CheckInGoalPeriod::class)],
            'comparison' => ['required', Rule::enum(CheckInGoalComparison::class)],
            'target' => ['required', 'numeric', ...$targetRange],
        ];
    }
}
