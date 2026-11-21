<?php

namespace App\Api\Goals\Requests;

use App\Api\Goals\Enums\GoalComparison;
use App\Api\Goals\Enums\GoalPeriod;
use App\Api\Goals\Enums\GoalSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGoalRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'source' => ['required', Rule::enum(GoalSource::class)],
            'check_in_tracker_id' => [
                'required_if:source,'.GoalSource::CheckInTracker->value,
                'integer',
                Rule::exists('check_in_trackers', 'id')->where('user_id', $this->user()->id),
            ],
            'period' => ['required', Rule::enum(GoalPeriod::class)],
            'comparison' => ['required', Rule::enum(GoalComparison::class)],
            'target' => 'required|numeric|min:0',
            'notify_when_reached' => 'sometimes|boolean',
        ];
    }
}
