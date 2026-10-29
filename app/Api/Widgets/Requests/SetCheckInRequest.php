<?php

namespace App\Api\Widgets\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetCheckInRequest extends FormRequest
{
    public function rules(): array
    {
        $max = $this->route('tracker') === 'sports' ? 6 : 5;

        return [
            'value' => 'present|nullable|integer|between:1,'.$max,
        ];
    }
}
