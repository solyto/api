<?php

namespace App\Api\Users\Requests;

use App\Api\Users\Enums\UserGrowthPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserGrowthRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'group_by' => ['required', 'string', Rule::enum(UserGrowthPeriod::class)],
        ];
    }
}
