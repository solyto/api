<?php

namespace App\Api\Users\Controllers;

use App\Api\ApiResponse;
use App\Api\CheckIn\Requests\UpdateCheckInSettingsRequest;
use App\Api\CheckIn\Resources\CheckInSettingsResource;
use App\Api\Users\Requests\UpdateCheckInCalendarMarkRequest;
use App\Api\Users\Requests\UpdateDateFormatRequest;
use App\Api\Users\Requests\UpdateJournalCalendarMarkRequest;
use App\Api\Users\Requests\UpdateJournalRequest;
use App\Api\Users\Requests\UpdateLanguageRequest;
use App\Api\Users\Requests\UpdateNavigationSettingsRequest;
use App\Api\Users\Requests\UpdateTimeFormatRequest;
use App\Api\Users\Requests\UpdateTimezoneRequest;
use App\Api\Users\Requests\UpdateWeatherCityRequest;
use App\Api\Users\Requests\UpdateWeatherTemperatureUnitRequest;
use App\Api\Users\Requests\UpdateWeekStartRequest;
use App\Api\Users\Services\UserSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserSettingsController
{
    public function __construct(private readonly UserSettingsService $userSettingsService) {}

    /**
     * @OA\Put(
     *     path="/users/me/settings/navigation",
     *     operationId="userSettingsUpdateNavigation",
     *     summary="Update navigation settings",
     *     tags={"User Settings"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"navigation"},
     *
     *             @OA\Property(
     *                 property="navigation",
     *                 type="string",
     *                 description="Client-owned JSON string, stored as sent. Current format: {""visible"": {<featureKey>: bool}, ""desktop"": [<slug>] | null, ""mobile"": [<slug>] | null}; a null order means not customised, the client uses its default. Legacy rows use the flat format {<featureKey>: bool}.",
     *                 example="{""visible"":{""calendar"":true,""checkIn"":false},""desktop"":[""home"",""calendar"",""check-in""],""mobile"":null}"
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Navigation updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     )
     * )
     */
    public function updateNavigation(UpdateNavigationSettingsRequest $request): JsonResponse
    {
        $this->userSettingsService->updateNavigation(
            $request->user(),
            json_decode($request->validated('navigation'), true) ?? []
        );

        return ApiResponse::success(null, 'Navigation updated successfully.');
    }

    /**
     * @OA\Put(
     *     path="/users/me/settings/language",
     *     operationId="userSettingsUpdateLanguage",
     *     summary="Update language setting",
     *     tags={"User Settings"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"language"},
     *
     *             @OA\Property(property="language", type="string")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Language updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     )
     * )
     */
    public function updateLanguage(UpdateLanguageRequest $request): JsonResponse
    {
        $this->userSettingsService->updateLanguage($request->user(), $request->validated('language'));

        return ApiResponse::success(null, 'Language updated successfully.');
    }

    /**
     * @OA\Put(
     *     path="/users/me/settings/timezone",
     *     operationId="userSettingsUpdateTimezone",
     *     summary="Update timezone setting",
     *     tags={"User Settings"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"timezone"},
     *
     *             @OA\Property(property="timezone", type="string")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Timezone updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     )
     * )
     */
    public function updateTimezone(UpdateTimezoneRequest $request): JsonResponse
    {
        $this->userSettingsService->updateTimezone($request->user(), $request->validated('timezone'));

        return ApiResponse::success(null, 'Timezone updated successfully.');
    }

    /**
     * @OA\Put(
     *     path="/users/me/settings/date-format",
     *     operationId="userSettingsUpdateDateFormat",
     *     summary="Update date format setting",
     *     tags={"User Settings"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"date_format"},
     *
     *             @OA\Property(property="date_format", type="string", maxLength=10)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Date format updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     )
     * )
     */
    public function updateDateFormat(UpdateDateFormatRequest $request): JsonResponse
    {
        $this->userSettingsService->updateDateFormat($request->user(), $request->validated('date_format'));

        return ApiResponse::success(null, 'Date format updated successfully.');
    }

    /**
     * @OA\Put(
     *     path="/users/me/settings/time-format",
     *     operationId="userSettingsUpdateTimeFormat",
     *     summary="Update time format setting",
     *     tags={"User Settings"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"time_format"},
     *
     *             @OA\Property(property="time_format", type="string", maxLength=10)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Time format updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     )
     * )
     */
    public function updateTimeFormat(UpdateTimeFormatRequest $request): JsonResponse
    {
        $this->userSettingsService->updateTimeFormat($request->user(), $request->validated('time_format'));

        return ApiResponse::success(null, 'Time format updated successfully.');
    }

    /**
     * @OA\Put(
     *     path="/users/me/settings/week-start",
     *     operationId="userSettingsUpdateWeekStart",
     *     summary="Update first day of the week setting",
     *     tags={"User Settings"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"week_start"},
     *
     *             @OA\Property(property="week_start", type="string", enum={"monday","sunday"})
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Week start updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     )
     * )
     */
    public function updateWeekStart(UpdateWeekStartRequest $request): JsonResponse
    {
        $this->userSettingsService->updateWeekStart($request->user(), $request->validated('week_start'));

        return ApiResponse::success(null, 'Week start updated successfully.');
    }

    /**
     * @OA\Put(
     *     path="/users/me/settings/weather-city",
     *     operationId="userSettingsUpdateWeatherCity",
     *     summary="Update weather city setting",
     *     tags={"User Settings"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"city","latitude","longitude"},
     *
     *             @OA\Property(property="city", type="string", maxLength=255),
     *             @OA\Property(property="latitude", type="number", minimum=-90, maximum=90),
     *             @OA\Property(property="longitude", type="number", minimum=-180, maximum=180)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Weather city updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     )
     * )
     */
    public function updateWeatherCity(UpdateWeatherCityRequest $request): JsonResponse
    {
        $this->userSettingsService->updateWeatherCity($request->user(), $request->validated());

        return ApiResponse::success(null, 'Weather city updated successfully.');
    }

    /**
     * @OA\Put(
     *     path="/users/me/settings/weather-temperature-unit",
     *     operationId="userSettingsUpdateTemperatureUnit",
     *     summary="Update temperature unit setting",
     *     tags={"User Settings"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"temperature_unit"},
     *
     *             @OA\Property(property="temperature_unit", type="string", maxLength=1),
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Weather temperature unit updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     )
     * )
     */
    public function updateWeatherTemperatureUnit(UpdateWeatherTemperatureUnitRequest $request): JsonResponse
    {
        $this->userSettingsService->updateWeatherTemperatureUnit($request->user(), $request->validated());

        return ApiResponse::success(null, 'Weather city updated successfully.');
    }

    /**
     * @OA\Put(
     *     path="/users/me/settings/complete-onboarding",
     *     operationId="userSettingsCompleteOnboarding",
     *     summary="Complete onboarding",
     *     tags={"User Settings"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Onboarding completed",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     )
     * )
     */
    public function completeOnboarding(Request $request): JsonResponse
    {
        $this->userSettingsService->completeOnboarding($request->user());

        return ApiResponse::success(null, 'Onboarding completed.');
    }

    /**
     * @OA\Put(
     *     path="/users/me/settings/journal",
     *     operationId="userSettingsUpdateJournal",
     *     summary="Enable or disable the journal",
     *     tags={"User Settings"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"enabled"},
     *
     *             @OA\Property(property="enabled", type="boolean")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Journal setting updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     )
     * )
     */
    public function updateJournal(UpdateJournalRequest $request): JsonResponse
    {
        $this->userSettingsService->updateJournal($request->user(), $request->boolean('enabled'));

        return ApiResponse::success(null, 'Journal setting updated successfully.');
    }

    /**
     * @OA\Put(
     *     path="/users/me/settings/journal-calendar-mark",
     *     operationId="userSettingsUpdateJournalCalendarMark",
     *     summary="Show or hide the journal mark in the calendar",
     *     tags={"User Settings"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"enabled"},
     *
     *             @OA\Property(property="enabled", type="boolean")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Journal calendar mark setting updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     )
     * )
     */
    public function updateJournalCalendarMark(UpdateJournalCalendarMarkRequest $request): JsonResponse
    {
        $this->userSettingsService->updateJournalCalendarMark($request->user(), $request->boolean('enabled'));

        return ApiResponse::success(null, 'Journal calendar mark setting updated successfully.');
    }

    /**
     * @OA\Put(
     *     path="/users/me/settings/check-in-calendar-mark",
     *     operationId="userSettingsUpdateCheckInCalendarMark",
     *     summary="Show or hide the check-in mark in the calendar",
     *     tags={"User Settings"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"enabled"},
     *
     *             @OA\Property(property="enabled", type="boolean")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Check-in calendar mark setting updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     )
     * )
     */
    public function updateCheckInCalendarMark(UpdateCheckInCalendarMarkRequest $request): JsonResponse
    {
        $this->userSettingsService->updateCheckInCalendarMark($request->user(), $request->boolean('enabled'));

        return ApiResponse::success(null, 'Check-in calendar mark setting updated successfully.');
    }

    /**
     * @OA\Get(
     *     path="/users/me/settings/check-in",
     *     operationId="userSettingsShowCheckIn",
     *     summary="Get check-in settings",
     *     tags={"User Settings"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Check-in settings retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/CheckInSettings")
     *         )
     *     )
     * )
     */
    public function showCheckIn(Request $request): JsonResponse
    {
        $settings = $this->userSettingsService->getCheckInSettings($request->user());

        return ApiResponse::success(
            new CheckInSettingsResource($settings),
            'Check-in settings retrieved successfully.'
        );
    }

    /**
     * @OA\Put(
     *     path="/users/me/settings/check-in",
     *     operationId="userSettingsUpdateCheckIn",
     *     summary="Update check-in settings",
     *     tags={"User Settings"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"enabled_trackers","selected_sports"},
     *
     *             @OA\Property(
     *                 property="enabled_trackers",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="string",
     *                     enum={"mood","sports","water","sleep","dreams","work","food_quality","food_amount","menstruation","alcohol","smoking"}
     *                 )
     *             ),
     *
     *             @OA\Property(
     *                 property="selected_sports",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="string",
     *                     enum={"dumbbell","bike","mountain","footprints","waves_ladder","yoga"}
     *                 ),
     *                 minItems=5,
     *                 maxItems=5
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Check-in settings updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/CheckInSettings")
     *         )
     *     )
     * )
     */
    public function updateCheckIn(UpdateCheckInSettingsRequest $request): JsonResponse
    {
        $settings = $this->userSettingsService->updateCheckInSettings($request->user(), $request->validated());

        return ApiResponse::success(
            new CheckInSettingsResource($settings),
            'Check-in settings updated successfully.'
        );
    }
}
