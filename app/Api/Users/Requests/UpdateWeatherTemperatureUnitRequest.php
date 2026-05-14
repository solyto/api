<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWeatherTemperatureUnitRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'temperature_unit' => 'string|in:c,f'
        ];
    }
}
