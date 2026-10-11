<?php

namespace App\Api\Planner\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePlannerEntryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date' => 'sometimes|date_format:Y-m-d',
            'title' => 'sometimes|string|filled|max:255',
            'start_minutes' => 'sometimes|integer|min:0|max:1439',
            'duration_minutes' => 'sometimes|integer|min:1',
            'is_all_day' => 'sometimes|boolean',
            'color' => 'sometimes|nullable|string|min:7|max:7',
            'todo_id' => 'sometimes|nullable|uuid',
            'sort_order' => 'sometimes|integer|min:0',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $entry = $this->route('entry');

            $start = $this->has('start_minutes')
                ? (int) $this->input('start_minutes')
                : (int) $entry->start_minutes;

            $duration = $this->has('duration_minutes')
                ? (int) $this->input('duration_minutes')
                : (int) $entry->duration_minutes;

            if (($start + $duration) > 1440) {
                $validator->errors()->add('duration_minutes', 'The start and duration must not exceed 1440 minutes.');
            }
        });
    }
}
