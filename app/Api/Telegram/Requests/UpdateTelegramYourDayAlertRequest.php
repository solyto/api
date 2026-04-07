<?php

namespace App\Api\Telegram\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTelegramYourDayAlertRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'your_day_alert' => 'required|boolean',
        ];
    }
}
