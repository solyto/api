<?php

namespace App\Api\Notifications\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Notifications\Requests\UpdateNotificationSettingsRequest;
use App\Api\Notifications\Resources\NotificationSettingsResource;
use App\Api\Users\Models\UserNotificationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationSettingsController
{
    use HandlesApiAuth;

    /**
     * @OA\Get(
     *     path="/notifications/settings",
     *     operationId="notificationSettingsShow",
     *     summary="Get notification settings",
     *     tags={"Notifications"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Notification settings retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/NotificationSettings")
     *         )
     *     )
     * )
     */
    public function show(Request $request): JsonResponse
    {
        $settings = UserNotificationSettings::firstOrCreate(
            ['user_id' => $request->user()->id],
            []
        );

        return ApiResponse::success(
            new NotificationSettingsResource($settings),
            'Notification settings retrieved successfully.'
        );
    }

    /**
     * @OA\Put(
     *     path="/notifications/settings",
     *     operationId="notificationSettingsUpdate",
     *     summary="Update notification settings",
     *     tags={"Notifications"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=false,
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="music_release_ui", type="boolean"),
     *             @OA\Property(property="music_release_email", type="boolean"),
     *             @OA\Property(property="music_release_push", type="boolean"),
     *             @OA\Property(property="music_release_telegram", type="boolean"),
     *             @OA\Property(property="book_release_ui", type="boolean"),
     *             @OA\Property(property="book_release_email", type="boolean"),
     *             @OA\Property(property="book_release_push", type="boolean"),
     *             @OA\Property(property="book_release_telegram", type="boolean"),
     *             @OA\Property(property="friend_request_ui", type="boolean"),
     *             @OA\Property(property="friend_request_email", type="boolean"),
     *             @OA\Property(property="friend_request_push", type="boolean"),
     *             @OA\Property(property="friend_request_telegram", type="boolean"),
     *             @OA\Property(property="daily_day_reminder_ui", type="boolean"),
     *             @OA\Property(property="daily_day_reminder_email", type="boolean"),
     *             @OA\Property(property="daily_day_reminder_push", type="boolean"),
     *             @OA\Property(property="daily_day_reminder_telegram", type="boolean"),
     *             @OA\Property(property="daily_check_in_reminder_ui", type="boolean"),
     *             @OA\Property(property="daily_check_in_reminder_email", type="boolean"),
     *             @OA\Property(property="daily_check_in_reminder_push", type="boolean"),
     *             @OA\Property(property="daily_check_in_reminder_telegram", type="boolean"),
     *             @OA\Property(property="calendar_share_ui", type="boolean"),
     *             @OA\Property(property="calendar_share_email", type="boolean"),
     *             @OA\Property(property="calendar_share_push", type="boolean"),
     *             @OA\Property(property="calendar_share_telegram", type="boolean"),
     *             @OA\Property(property="dev_request_comment_ui", type="boolean"),
     *             @OA\Property(property="dev_request_comment_email", type="boolean"),
     *             @OA\Property(property="dev_request_comment_push", type="boolean"),
     *             @OA\Property(property="dev_request_comment_telegram", type="boolean")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Notification settings updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/NotificationSettings")
     *         )
     *     )
     * )
     */
    public function update(UpdateNotificationSettingsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $settings = UserNotificationSettings::updateOrCreate(
            ['user_id' => $request->user()->id],
            $validated
        );

        return ApiResponse::success(
            new NotificationSettingsResource($settings),
            'Notification settings updated successfully.'
        );
    }
}
