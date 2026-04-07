<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'current_password' => 'required|string',
            'new_password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    public function attributes(): array
    {
        return [
            'current_password' => 'Current password',
            'new_password' => 'New password',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (!Hash::check($this->current_password, $this->user()->password)) {
                $validator->errors()->add(
                    'current_password',
                    'The current password is incorrect.'
                );
            }

            if ($this->current_password === $this->new_password) {
                $validator->errors()->add(
                    'new_password',
                    'The new password must be different from your current password.'
                );
            }
        });
    }
}
