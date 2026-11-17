<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PasskeyAuthenticateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // The AuthenticationResponseJSON from @simplewebauthn/browser.
            'response'                            => 'required|array',
            'response.id'                         => 'required|string',
            'response.rawId'                      => 'sometimes|string',
            'response.response'                   => 'required|array',
            'response.response.clientDataJSON'    => 'required|string',
            'response.response.authenticatorData' => 'required|string',
            'response.response.signature'         => 'required|string',
            'platform'                            => 'nullable|string|in:web,mobile,desktop',
        ];
    }
}
