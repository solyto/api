<?php

namespace App\Api\CheckIn\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ListCheckInMarksRequest extends FormRequest
{
    private const int MAX_DAYS = 366;

    public function rules(): array
    {
        return [
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (Carbon::parse($this->input('from'))->diffInDays(Carbon::parse($this->input('to'))) > self::MAX_DAYS) {
                    $validator->errors()->add('to', 'The date range must not exceed 366 days.');
                }
            },
        ];
    }
}
