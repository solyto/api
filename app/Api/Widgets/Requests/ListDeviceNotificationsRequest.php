<?php

namespace App\Api\Widgets\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListDeviceNotificationsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'device_id' => 'required|string|max:64|regex:/^[A-Za-z0-9-]+$/',
            'after' => 'required|integer|min:0',
        ];
    }
}
