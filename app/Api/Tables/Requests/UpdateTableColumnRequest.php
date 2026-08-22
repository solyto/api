<?php

namespace App\Api\Tables\Requests;

use App\Api\Tables\Enums\TableColumnTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTableColumnRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'type' => ['sometimes', 'required', 'string', Rule::in(array_column(TableColumnTypeEnum::cases(), 'value'))],
            'options' => 'nullable|array',
            'options.*' => 'string|max:255',
        ];
    }
}
