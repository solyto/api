<?php

namespace App\Api\Users\Controllers;

use App\Api\ApiResponse;
use App\Api\Users\Models\Passkey;
use App\Api\Users\Requests\PasskeyAuthenticateRequest;
use App\Api\Users\Requests\PasskeyRegisterRequest;
use App\Api\Users\Resources\UserResource;
use App\Api\Users\Services\PasskeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasskeyController
{
    public function __construct(private readonly PasskeyService $passkeyService) {}

    public function authenticationOptions(Request $request): JsonResponse
    {
        $options = $this->passkeyService->authenticationOptions($request->ip());
        return ApiResponse::success($options, 'Authentication options generated.');
    }

    public function authenticate(PasskeyAuthenticateRequest $request): JsonResponse
    {
        try {
            $result = $this->passkeyService->authenticate(
                $request->validated('response'),
                $request->ip()
            );
        } catch (\RuntimeException $e) {
            return ApiResponse::error('Authentication failed.', 401);
        }

        return ApiResponse::success(
            array_merge(['user' => new UserResource($result['user'])], $result['token_data']),
            'Signed in successfully.'
        );
    }

    public function registerOptions(Request $request): JsonResponse
    {
        $user = $request->user()->load('passkeys');
        $options = $this->passkeyService->registrationOptions($user);
        return ApiResponse::success($options, 'Registration options generated.');
    }

    public function register(PasskeyRegisterRequest $request): JsonResponse
    {
        try {
            $passkey = $this->passkeyService->register(
                $request->user(),
                $request->validated('response'),
                $request->validated('name') ?? 'Passkey'
            );
        } catch (\RuntimeException $e) {
            return ApiResponse::error('Registration failed.', 422);
        }

        return ApiResponse::success([
            'id'           => $passkey->id,
            'name'         => $passkey->name,
            'created_at'   => $passkey->created_at,
            'last_used_at' => $passkey->last_used_at,
            'transports'   => $passkey->transports,
        ], 'Passkey registered successfully.');
    }

    public function index(Request $request): JsonResponse
    {
        $passkeys = $request->user()->passkeys()->orderBy('created_at', 'desc')->get()->map(fn (Passkey $p) => [
            'id'           => $p->id,
            'name'         => $p->name,
            'created_at'   => $p->created_at,
            'last_used_at' => $p->last_used_at,
            'transports'   => $p->transports,
        ]);

        return ApiResponse::success($passkeys);
    }

    public function update(Request $request, Passkey $passkey): JsonResponse
    {
        if ($passkey->user_id !== $request->user()->id) {
            return ApiResponse::forbidden('You do not own this passkey.');
        }

        $request->validate(['name' => 'required|string|max:50']);
        $passkey->update(['name' => $request->input('name')]);

        return ApiResponse::success([
            'id'           => $passkey->id,
            'name'         => $passkey->name,
            'created_at'   => $passkey->created_at,
            'last_used_at' => $passkey->last_used_at,
            'transports'   => $passkey->transports,
        ], 'Passkey renamed.');
    }

    public function destroy(Request $request, Passkey $passkey): JsonResponse
    {
        if ($passkey->user_id !== $request->user()->id) {
            return ApiResponse::forbidden('You do not own this passkey.');
        }

        $passkey->delete();

        return ApiResponse::success(null, 'Passkey deleted.');
    }
}
