<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PasskeyRegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // The RegistrationResponseJSON from @simplewebauthn/browser.
            'response'                            => 'required|array',
            'response.response'                   => 'required|array',
            'response.response.clientDataJSON'    => 'required|string',
            'response.response.attestationObject' => 'required|string',
            'response.response.transports'        => 'sometimes|nullable|array',
            'name'                                => 'sometimes|string|max:50',
        ];
    }
}
