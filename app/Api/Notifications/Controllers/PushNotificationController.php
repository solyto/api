<?php

namespace App\Api\Notifications\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Notifications\Requests\SubscribeRequest;
use App\Api\Notifications\Requests\UnsubscribeRequest;
use Illuminate\Http\JsonResponse;

class PushNotificationController
{
    use HandlesApiAuth;

    /**
     * @OA\Get(
     *     path="/v1/notifications/push/public-key",
     *     operationId="pushNotificationGetPublicKey",
     *     summary="Get VAPID public key",
     *     tags={"Notifications"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Public key retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="string")
     *         )
     *     )
     * )
     */
    public function getPublicKey(): JsonResponse
    {
        return ApiResponse::success(
            config('webpush.vapid.public_key'),
            'Public key retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/v1/notifications/push/subscribe",
     *     operationId="pushNotificationSubscribe",
     *     summary="Subscribe to push notifications",
     *     tags={"Notifications"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"endpoint","keys"},
     *
     *             @OA\Property(property="endpoint", type="string"),
     *             @OA\Property(
     *                 property="keys",
     *                 type="object",
     *                 required={"auth","p256dh"},
     *                 @OA\Property(property="auth", type="string"),
     *                 @OA\Property(property="p256dh", type="string")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Subscription saved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     )
     * )
     */
    public function subscribe(SubscribeRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();
        $user->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth']
        );

        return ApiResponse::success(null, 'Subscription saved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/v1/notifications/push/unsubscribe",
     *     operationId="pushNotificationUnsubscribe",
     *     summary="Unsubscribe from push notifications",
     *     tags={"Notifications"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"endpoint"},
     *
     *             @OA\Property(property="endpoint", type="string")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Subscription deleted successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     )
     * )
     */
    public function unsubscribe(UnsubscribeRequest $request)
    {
        $validated = $request->validated();
        $user = $request->user();
        $user->deletePushSubscription($validated['endpoint']);

        return ApiResponse::success(null, 'Subscription deleted successfully.');
    }
}
