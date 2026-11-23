<?php

namespace App\Api\Alerts\Requests;

use App\Api\Alerts\Enums\AlertRule;
use App\Api\Alerts\Enums\AlertSource;
use App\Api\Alerts\Services\AlertEvaluator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAlertRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'source' => ['required', Rule::in([AlertSource::CheckInTracker->value])],
            'check_in_tracker_id' => ['required', 'integer', Rule::exists('check_in_trackers', 'id')->where('user_id', $this->user()->id)],
            'rule' => ['required', Rule::enum(AlertRule::class)->except(AlertRule::GoalReached)],
            'threshold' => 'nullable|numeric|min:0|max:1000000',
            'days' => 'required|integer|min:1|max:'.AlertEvaluator::MAX_DAYS,
        ];
    }
}
