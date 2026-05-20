<?php

namespace App\Api\Todos\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTodoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string|max:1000',
            'link' => 'sometimes|nullable|string|max:2048',
            'priority' => 'sometimes|in:low,medium,high',
            'due_at' => 'sometimes|nullable|date|after_or_equal:today',
            'status' => 'sometimes|in:backlog,pending,in-progress,waiting,almost-done',
            'progress' => 'sometimes|nullable|integer|min:0|max:100',
            'effort' => 'sometimes|nullable|in:low,medium,high',
            'is_completed' => 'sometimes|boolean',
            'category_id' => 'sometimes|nullable|exists:todo_categories,id',
            'tags' => 'sometimes|nullable|array',
            'tags.*' => 'exists:tags,id',
            'recurrence_frequency' => 'sometimes|nullable|in:daily,weekly,monthly,yearly',
            'recurrence_interval' => 'sometimes|integer|min:1|max:365',
            'recurrence_ends_at' => 'sometimes|nullable|date|after:today',
        ];
    }

    public function attributes(): array
    {
        return [
            'due_at' => 'due date',
            'is_completed' => 'completion status',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('due_at')) {
            $formats = [
                'd.m.Y',    // dd.mm.YYYY
                'd.m.y',    // dd.mm.YY
                'Y-m-d',    // YYYY-mm-dd
                'y-m-d'     // YY-mm-dd
            ];

            foreach ($formats as $format) {
                try {
                    $date = Carbon::createFromFormat($format, $this->due_at);

                    // For two-digit years, ensure we're in the future
                    if (strlen($format) === 5) { // formats with 'y' instead of 'Y'
                        $currentYear = now()->year;
                        $twoDigitYear = (int)$date->format('y');
                        $fullYear = (int)($currentYear / 100) * 100 + $twoDigitYear;

                        if ($fullYear < $currentYear) {
                            $fullYear += 100;
                        }

                        $date->setYear($fullYear);
                    }

                    $this->merge([
                        'due_at' => $date->format('Y-m-d')
                    ]);
                    break;
                } catch (\Exception $e) {
                    continue;
                }
            }
        } elseif (empty($this->due_date)) {
            $this->request->remove('due_at');
        }

        if ($this->has('description') && empty($this->description)) {
            $this->merge(['description' => null]);
        }

        if ($this->has('link') && empty($this->link)) {
            $this->merge(['link' => null]);
        }

        if ($this->has('is_completed')) {
            $value = $this->is_completed;
            if (is_string($value)) {
                $this->merge([
                    'is_completed' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ]);
            }
        }
    }
}
