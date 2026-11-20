<?php

namespace App\Api\Goals\Requests;

use App\Api\Goals\Enums\GoalComparison;
use App\Api\Goals\Enums\GoalPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGoalRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'period' => ['sometimes', Rule::enum(GoalPeriod::class)],
            'comparison' => ['sometimes', Rule::enum(GoalComparison::class)],
            'target' => 'sometimes|numeric|min:0',
            'notify_when_reached' => 'sometimes|boolean',
        ];
    }
}
