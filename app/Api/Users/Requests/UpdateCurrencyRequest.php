<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCurrencyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'currency' => 'required|string|in:EUR,USD,GBP,CHF,CAD,AUD,RUB',
        ];
    }
}
