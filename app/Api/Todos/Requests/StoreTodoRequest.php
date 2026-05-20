<?php

namespace App\Api\Todos\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class StoreTodoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'link' => 'nullable|string|max:2048',
            'priority' => 'sometimes|in:low,medium,high',
            'due_at' => 'nullable|date|after_or_equal:today',
            'status' => 'sometimes|in:backlog,pending,in-progress,waiting,almost-done',
            'progress' => 'sometimes|nullable|integer|min:0|max:100',
            'effort' => 'sometimes|nullable|in:low,medium,high',
            'category_id' => 'nullable|exists:todo_categories,id',
            'tags' => 'nullable|array',
            'tags.*' => 'exists:tags,id',
            'recurrence_frequency' => 'nullable|in:daily,weekly,monthly,yearly',
            'recurrence_interval' => 'sometimes|integer|min:1|max:365',
            'recurrence_ends_at' => 'nullable|date|after:today',
        ];
    }

    public function attributes(): array
    {
        return [
            'due_at' => 'due date',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (!$this->has('priority')) {
            $this->merge(['priority' => 'medium']);
        }

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

        if (empty($this->due_at)) {
            $this->merge([
                'recurrence_frequency' => null,
                'recurrence_ends_at' => null,
            ]);
        }
    }
}
