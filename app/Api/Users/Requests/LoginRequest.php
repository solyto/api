<?php

namespace App\Api\Users\Requests;

use App\Api\Users\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|string|email',
            'password' => 'required|string',
            'platform' => 'nullable|string|in:web,mobile,desktop',
        ];
    }

    public function authenticate(): User
    {
        if (!Auth::attempt($this->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user = Auth::user();

        if (config('auth.policy.confirmation') && !$user->email_verified_at) {
            throw ValidationException::withMessages([
                'email' => ['Please verify your email address first.'],
            ]);
        }

        return $user;
    }
}
