<?php

namespace App\Api\Telegram\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Telegram\Models\TelegramBotConnection;
use App\Api\Telegram\Requests\UpdateTelegramCheckInAlertRequest;
use App\Api\Telegram\Requests\UpdateTelegramYourDayAlertRequest;
use App\Api\Telegram\Resources\TelegramBotConnectionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramBotConnectionController
{
    use HandlesApiAuth;

    /**
     * @OA\Get(
     *     path="/api/telegram/token",
     *     operationId="telegramBotConnectionGetToken",
     *     tags={"Telegram"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Token retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", type="string"),
     *             @OA\Property(property="message", type="string", example="Token retrieved successfully.")
     *         )
     *     )
     * )
     */
    public function getToken(Request $request): JsonResponse
    {
        $token = TelegramBotConnection::forUser($request->user()->id)->where('created_at', '>', now()->subDays(1))->first();

        if (! $token) {
            TelegramBotConnection::forUser($request->user()->id)->delete();
            $token = TelegramBotConnection::create([
                'user_id' => $request->user()->id,
                'token' => bin2hex(random_bytes(32)),
            ]);
        }

        return ApiResponse::success(
            $token->token,
            'Token retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/telegram/connection",
     *     operationId="telegramBotConnectionGetRequest",
     *     tags={"Telegram"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Token retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TelegramBotConnection"),
     *             @OA\Property(property="message", type="string", example="Token retrieved successfully.")
     *         )
     *     )
     * )
     */
    public function getRequest(Request $request): JsonResponse
    {
        $connection = TelegramBotConnection::forUser($request->user()->id)->first();

        return ApiResponse::success(
            new TelegramBotConnectionResource($connection),
            'Token retrieved successfully.'
        );
    }

    /**
     * @OA\Put(
     *     path="/api/telegram/your-day-alert",
     *     operationId="telegramBotConnectionUpdateYourDayAlert",
     *     tags={"Telegram"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"your_day_alert"},
     *
     *             @OA\Property(property="your_day_alert", type="boolean")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Your day alert updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TelegramBotConnection"),
     *             @OA\Property(property="message", type="string", example="Your day alert updated successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function updateYourDayAlert(UpdateTelegramYourDayAlertRequest $request): JsonResponse
    {
        $connection = TelegramBotConnection::forUser($request->user()->id)->first();

        $connection->update($request->validated());

        return ApiResponse::success(
            new TelegramBotConnectionResource($connection),
            'Your day alert updated successfully.'
        );
    }

    /**
     * @OA\Put(
     *     path="/api/telegram/check-in-alert",
     *     operationId="telegramBotConnectionUpdateCheckInAlert",
     *     tags={"Telegram"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"check_in_alert"},
     *
     *             @OA\Property(property="check_in_alert", type="boolean")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Check-in alert updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TelegramBotConnection"),
     *             @OA\Property(property="message", type="string", example="Check-in alert updated successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function updateCheckInAlert(UpdateTelegramCheckInAlertRequest $request): JsonResponse
    {
        $connection = TelegramBotConnection::forUser($request->user()->id)->first();

        $connection->update($request->validated());

        return ApiResponse::success(
            new TelegramBotConnectionResource($connection),
            'Check-in alert updated successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/api/telegram/confirm-token",
     *     operationId="telegramBotConnectionConfirmToken",
     *     tags={"Telegram"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Token confirmed successfully"
     *     )
     * )
     */
    public function confirmToken(Request $request): JsonResponse {}
}
