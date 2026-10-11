<?php

namespace App\Api\Planner\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StorePlannerEntryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date' => 'required|date_format:Y-m-d',
            'title' => 'required|string|max:255',
            'start_minutes' => 'required|integer|min:0|max:1439',
            'duration_minutes' => 'required|integer|min:1',
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

            if (((int) $this->input('start_minutes') + (int) $this->input('duration_minutes')) > 1440) {
                $validator->errors()->add('duration_minutes', 'The start and duration must not exceed 1440 minutes.');
            }
        });
    }
}
