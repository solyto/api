<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWeatherCityRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'city' => 'required|string|max:255',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ];
    }
}
