<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJournalCalendarMarkRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'enabled' => 'required|boolean',
        ];
    }
}
