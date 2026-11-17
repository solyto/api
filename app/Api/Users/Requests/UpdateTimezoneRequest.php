<?php

namespace App\Api\Users\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTimezoneRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'timezone' => [
                'bail',
                'required',
                'string',
                // Not Laravel's `timezone` rule: its identifier list differs
                // from what DateTimeZone accepts (e.g. Etc/GMT+1), and the
                // reminder commands parse the stored value with DateTimeZone.
                function (string $attribute, mixed $value, Closure $fail) {
                    try {
                        new \DateTimeZone($value);
                    } catch (\Exception) {
                        $fail('The :attribute must be a valid timezone.');
                    }
                },
            ],
        ];
    }
}
