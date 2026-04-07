<?php

namespace App\Api\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function rules(): array
    {
        $userId = $this->route('user')->id;
        $currentUser = $this->user();

        $rules = [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $userId,
            'password' => ['sometimes', 'confirmed', Password::defaults()],
            'current_password' => 'required_with:password|current_password'
        ];

        if ($currentUser->isAdmin()) {
            $allowedRoles = $currentUser->isSuperAdmin()
                ? ['user', 'admin', 'super_admin']
                : ['user', 'admin'];

            $rules['role'] = ['sometimes', 'string', Rule::in($allowedRoles)];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        if (empty($this->password)) {
            $this->request->remove('password');
            $this->request->remove('password_confirmation');
            $this->request->remove('current_password');
        }

        $currentUser = $this->user();
        if (!$currentUser->isAdmin()) {
            $this->request->remove('role');
        }
    }
}
