<?php

namespace App\Api\Alerts\Requests;

use App\Api\Alerts\Enums\AlertRule;
use App\Api\Alerts\Services\AlertEvaluator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAlertRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'rule' => ['sometimes', Rule::enum(AlertRule::class)->except(AlertRule::GoalReached)],
            'threshold' => 'sometimes|nullable|numeric|min:0|max:1000000',
            'days' => 'sometimes|integer|min:1|max:'.AlertEvaluator::MAX_DAYS,
            'enabled' => 'sometimes|boolean',
        ];
    }
}
