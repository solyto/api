<?php

namespace App\Api\Widgets\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\TimeTracking\Models\TimeTrackingProject;
use App\Api\Todos\Models\Todo;
use App\Api\Widgets\Requests\SetCheckInRequest;
use App\Api\Widgets\Resources\CheckInWidgetResource;
use App\Api\Widgets\Resources\TimeTrackingWidgetResource;
use App\Api\Widgets\Resources\YourDayResource;
use App\Api\Widgets\Services\WidgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WidgetController
{
    use HandlesApiAuth;

    public function __construct(private readonly WidgetService $widgetService) {}

    /**
     * @OA\Get(
     *     path="/widgets/your-day",
     *     operationId="widgetsYourDay",
     *     summary="Your Day widget data, display-ready in the user's language, timezone and formats",
     *     tags={"Widgets"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Widget data retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/YourDayWidget")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=426, description="Client version too old", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=429, description="Too many requests", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function yourDay(Request $request): JsonResponse
    {
        return ApiResponse::success(
            new YourDayResource($this->widgetService->yourDay($request->user())),
            'Widget data retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/widgets/actions/todo.complete/{todo}",
     *     operationId="widgetsTodoComplete",
     *     summary="Complete a todo from a widget; completing an already completed todo changes nothing",
     *     tags={"Widgets"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="todo",
     *         in="path",
     *         description="Todo ID",
     *         required=true,
     *
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Todo completed successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="object", nullable=true, example=null)
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Todo not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=426, description="Client version too old", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=429, description="Too many requests", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function completeTodo(Request $request, Todo $todo): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $todo), 403);

        $this->widgetService->completeTodo($request->user(), $todo);

        return ApiResponse::success(null, 'Todo completed successfully.');
    }

    /**
     * @OA\Get(
     *     path="/widgets/time-tracking",
     *     operationId="widgetsTimeTracking",
     *     summary="Time tracking widget data: the running timer and the projects to start, in the user's language and timezone",
     *     tags={"Widgets"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Widget data retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TimeTrackingWidget")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=426, description="Client version too old", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=429, description="Too many requests", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function timeTracking(Request $request): JsonResponse
    {
        return ApiResponse::success(
            new TimeTrackingWidgetResource($this->widgetService->timeTracking($request->user())),
            'Widget data retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/widgets/actions/time_tracking.start/{project}",
     *     operationId="widgetsTimeTrackingStart",
     *     summary="Start a project's timer from a widget, stopping any other running timer; a no-op while the project's timer runs",
     *     tags={"Widgets"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="project",
     *         in="path",
     *         description="Time tracking project ID",
     *         required=true,
     *
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Timer started successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="object", nullable=true, example=null)
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Project not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=426, description="Client version too old", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=429, description="Too many requests", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function startTimeTracking(Request $request, TimeTrackingProject $project): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $project), 403);

        $this->widgetService->startTimeTracking($request->user(), $project);

        return ApiResponse::success(null, 'Timer started successfully.');
    }

    /**
     * @OA\Post(
     *     path="/widgets/actions/time_tracking.stop/{entry}",
     *     operationId="widgetsTimeTrackingStop",
     *     summary="Stop a running timer from a widget; stopping an already stopped entry changes nothing",
     *     tags={"Widgets"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="entry",
     *         in="path",
     *         description="Time tracking entry ID",
     *         required=true,
     *
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Timer stopped successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="object", nullable=true, example=null)
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Entry not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=426, description="Client version too old", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=429, description="Too many requests", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function stopTimeTracking(Request $request, TimeTrackingEntry $entry): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $entry), 403);

        $this->widgetService->stopTimeTracking($request->user(), $entry);

        return ApiResponse::success(null, 'Timer stopped successfully.');
    }

    /**
     * @OA\Get(
     *     path="/widgets/check-in",
     *     operationId="widgetsCheckIn",
     *     summary="Check-in widget data: today's check-in for the enabled trackers with the values to tap, in the user's language and timezone",
     *     tags={"Widgets"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Widget data retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/CheckInWidget")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=426, description="Client version too old", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=429, description="Too many requests", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function checkIn(Request $request): JsonResponse
    {
        return ApiResponse::success(
            new CheckInWidgetResource($this->widgetService->checkIn($request->user())),
            'Widget data retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/widgets/actions/check_in.set/{tracker}",
     *     operationId="widgetsCheckInSet",
     *     summary="Set one tracker of today's check-in (user's timezone) from a widget, or clear it with null; setting the stored value again changes nothing",
     *     tags={"Widgets"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="tracker",
     *         in="path",
     *         description="Tracker key",
     *         required=true,
     *
     *         @OA\Schema(type="string", enum={"mood","sleep","dreams","food_quality","food_amount","water","sports","work","menstruation","alcohol","smoking","social_life"})
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"value"},
     *
     *             @OA\Property(property="value", type="integer", nullable=true, minimum=1, maximum=6, description="1-5 (sports: sport id 1-6), or null to clear", example=4)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Check-in saved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="object", nullable=true, example=null)
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Unknown tracker", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Invalid value", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=426, description="Client version too old", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=429, description="Too many requests", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function setCheckIn(SetCheckInRequest $request, string $tracker): JsonResponse
    {
        $value = $request->validated('value');

        $this->widgetService->setCheckIn($request->user(), $tracker, $value === null ? null : (int) $value);

        return ApiResponse::success(null, 'Check-in saved successfully.');
    }
}
