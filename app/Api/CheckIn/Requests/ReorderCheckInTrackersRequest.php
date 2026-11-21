<?php

namespace App\Api\CheckIn\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderCheckInTrackersRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|distinct',
        ];
    }
}
