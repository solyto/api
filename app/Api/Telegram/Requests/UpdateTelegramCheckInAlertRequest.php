<?php

namespace App\Api\Telegram\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTelegramCheckInAlertRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'check_in_alert' => 'required|boolean',
        ];
    }
}
