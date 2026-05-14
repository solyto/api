<?php

namespace App\Api\Users\Controllers;

use App\Api\ApiResponse;
use App\Api\Users\Requests\LoginRequest;
use App\Api\Users\Requests\RegisterRequest;
use App\Api\Users\Requests\VerifyRequest;
use App\Api\Users\Resources\UserResource;
use App\Api\Users\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController
{
    public function __construct(private readonly AuthService $authService) {}

    /**
     * @OA\Post(
     *     path="/v1/auth/register",
     *     operationId="authRegister",
     *     summary="Register a new user",
     *     tags={"Auth"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name","email","password"},
     *
     *             @OA\Property(property="name", type="string", maxLength=255),
     *             @OA\Property(property="email", type="string", format="email", maxLength=255),
     *             @OA\Property(property="password", type="string"),
     *             @OA\Property(property="password_confirmation", type="string"),
     *             @OA\Property(property="language", type="string", enum={"en","de","fr","es"})
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="User registered successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     )
     * )
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            $this->authService->register($request->validated());
        } catch (\RuntimeException $e) {
            report($e);
            return ApiResponse::serverError($e->getMessage());
        }

        return ApiResponse::success([], 'User registered successfully.', 201);
    }

    /**
     * @OA\Post(
     *     path="/v1/auth/login",
     *     operationId="authLogin",
     *     summary="Login user",
     *     tags={"Auth"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"email","password"},
     *
     *             @OA\Property(property="email", type="string", format="email"),
     *             @OA\Property(property="password", type="string")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Login successful",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="user", ref="#/components/schemas/User"),
     *                 @OA\Property(property="token", type="string"),
     *                 @OA\Property(property="token_type", type="string")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $user = $request->authenticate();
        } catch (ValidationException $e) {
            report($e);
            return ApiResponse::unauthorized();
        }

        $tokenData = $this->authService->createToken($user);

        return ApiResponse::success(
            array_merge(['user' => new UserResource($user)], $tokenData),
            'Login successful.'
        );
    }

    /**
     * @OA\Post(
     *     path="/v1/auth/logout",
     *     operationId="authLogout",
     *     summary="Logout user",
     *     tags={"Auth"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Logout successful",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     )
     * )
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return ApiResponse::success(null, 'Logout successful.');
    }

    /**
     * @OA\Post(
     *     path="/v1/auth/logoutAll",
     *     operationId="authLogoutAll",
     *     summary="Logout from all devices",
     *     tags={"Auth"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Logged out from all devices",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     )
     * )
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $this->authService->logoutAll($request->user());

        return ApiResponse::success(null, 'Logged out from all devices.');
    }

    /**
     * @OA\Post(
     *     path="/v1/auth/refresh",
     *     operationId="authRefresh",
     *     summary="Refresh authentication token",
     *     tags={"Auth"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Token refreshed successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="user", ref="#/components/schemas/User"),
     *                 @OA\Property(property="token", type="string"),
     *                 @OA\Property(property="token_type", type="string")
     *             )
     *         )
     *     )
     * )
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $tokenData = $this->authService->refreshToken($user);

        return ApiResponse::success(
            array_merge(['user' => new UserResource($user)], $tokenData),
            'Token refreshed sucessfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/v1/auth/verify",
     *     operationId="authVerify",
     *     summary="Verify user email",
     *     tags={"Auth"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"user_id","token"},
     *
     *             @OA\Property(property="user_id", type="string"),
     *             @OA\Property(property="token", type="string")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="User verified successfully",
     *
     *         @OA\JsonContent(ref="#/components/schemas/SuccessResponse")
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated - already_verified, token_mismatch, token_expired", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function verify(VerifyRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->verify($request->validated());
        } catch (\RuntimeException $e) {
            report($e);
            return match ($e->getMessage()) {
                'already_verified' => ApiResponse::error('Account already verified', 401, ['already_verified']),
                'token_mismatch' => ApiResponse::error('No such verification token', 401, ['token_mismatch']),
                'token_expired' => ApiResponse::error('Verification token has expired', 401, ['token_expired']),
                default => ApiResponse::error('Provided data do not match any user.', 401),
            };
        }

        if (! $result) {
            return ApiResponse::error('Provided data do not match any user.', 401);
        }

        return ApiResponse::success([], 'User successfully confirmed.', 201);
    }

    /**
     * @OA\Get(
     *     path="/v1/auth/tokens",
     *     operationId="authTokens",
     *     summary="List user tokens",
     *     tags={"Auth"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Tokens retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="name", type="string"),
     *                 @OA\Property(property="last_used_at", type="string", format="date-time", nullable=true),
     *                 @OA\Property(property="created_at", type="string", format="date-time")
     *             ))
     *         )
     *     )
     * )
     */
    public function tokens(Request $request): JsonResponse
    {
        $tokens = $this->authService->listTokens($request->user());

        return ApiResponse::success($tokens, 'Tokens retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/v1/auth/revokeToken",
     *     operationId="authRevokeToken",
     *     summary="Revoke a token",
     *     tags={"Auth"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"token_id"},
     *
     *             @OA\Property(property="token_id", type="integer")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Token revoked successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=404, description="Token not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function revokeToken(Request $request, $tokenId): JsonResponse
    {
        try {
            $result = $this->authService->revokeToken($request->user(), (int) $tokenId);
        } catch (\RuntimeException $e) {
            report($e);
            return ApiResponse::error($e->getMessage(), 400);
        }

        if ($result === null) {
            return ApiResponse::notFound('Token not found');
        }

        return ApiResponse::success(null, 'Token revoked successfully');
    }
}
