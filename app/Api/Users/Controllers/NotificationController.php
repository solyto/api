<?php

namespace App\Api\Users\Controllers;

use App\Api\ApiResponse;
use App\Api\Users\Resources\NotificationResource;
use App\Api\Users\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController
{
    public function __construct(private readonly NotificationService $notificationService) {}

    /**
     * @OA\Get(
     *     path="/v1/notifications",
     *     operationId="notificationList",
     *     summary="List notifications",
     *     tags={"Notifications"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Notifications retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Notification"))
     *         )
     *     )
     * )
     */
    public function list(Request $request): JsonResponse
    {
        return ApiResponse::success(
            NotificationResource::collection($this->notificationService->listUnread($request->user())),
            'Notifications retrieved successfully.'
        );
    }

    /**
     * @OA\Put(
     *     path="/v1/notifications/{id}/mark-read",
     *     operationId="notificationMarkRead",
     *     summary="Mark notification as read",
     *     tags={"Notifications"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Notification ID",
     *         required=true,
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Notification marked as read successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     )
     * )
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $this->notificationService->markRead($request->user(), $id);

        return ApiResponse::success([], 'Notification marked as read successfully.');
    }
}
