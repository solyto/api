<?php

namespace App\Api\CheckIn\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCheckInRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date' => 'required|date',
            'mood' => 'sometimes|nullable|in:1,2,3,4,5',
            'water' => 'sometimes|nullable|in:1,2,3,4,5',
            'sleep' => 'sometimes|nullable|in:1,2,3,4,5',
            'dreams' => 'sometimes|nullable|in:1,2,3,4,5',
            'work' => 'sometimes|nullable|in:1,2,3,4,5',
            'sports' => 'sometimes|nullable|in:1,2,3,4,5,6',
            'food_quality' => 'sometimes|nullable|in:1,2,3,4,5',
            'food_amount' => 'sometimes|nullable|in:1,2,3,4,5',
            'menstruation' => 'sometimes|nullable|in:1,2,3,4,5',
            'alcohol' => 'sometimes|nullable|in:1,2,3,4,5',
            'smoking' => 'sometimes|nullable|in:1,2,3,4,5',
        ];
    }
}
