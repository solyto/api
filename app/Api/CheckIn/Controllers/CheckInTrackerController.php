<?php

namespace App\Api\CheckIn\Controllers;

use App\Api\ApiResponse;
use App\Api\CheckIn\Models\CheckInAlert;
use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\CheckIn\Requests\CheckInAlertRequest;
use App\Api\CheckIn\Requests\ReorderCheckInTrackersRequest;
use App\Api\CheckIn\Requests\SetCheckInGoalRequest;
use App\Api\CheckIn\Requests\StoreCheckInTrackerRequest;
use App\Api\CheckIn\Requests\UpdateCheckInTrackerRequest;
use App\Api\CheckIn\Resources\CheckInTrackerResource;
use App\Api\CheckIn\Services\CheckInTrackerService;
use App\Api\HandlesApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckInTrackerController
{
    use HandlesApiAuth;

    public function __construct(private readonly CheckInTrackerService $trackerService) {}

    /**
     * @OA\Get(
     *     path="/check-in/trackers",
     *     operationId="checkInTrackerIndex",
     *     summary="List the user's trackers, built-in and own, in their order",
     *     tags={"Check-in"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Trackers retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/CheckInTracker"))
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            CheckInTrackerResource::collection($this->trackerService->list($request->user())),
            'Trackers retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/check-in/trackers",
     *     operationId="checkInTrackerStore",
     *     summary="Create an own tracker",
     *     tags={"Check-in"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name", "type"},
     *
     *             @OA\Property(property="name", type="string", maxLength=50),
     *             @OA\Property(property="type", type="string", enum={"scale", "yes_no", "count"}),
     *             @OA\Property(property="icon", type="string", maxLength=64, nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Tracker created successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/CheckInTracker")
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function store(StoreCheckInTrackerRequest $request): JsonResponse
    {
        $tracker = $this->trackerService->create($request->user(), $request->validated());

        return ApiResponse::success(new CheckInTrackerResource($tracker), 'Tracker created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/check-in/trackers/order",
     *     operationId="checkInTrackerReorder",
     *     summary="Set the order of all of the user's trackers",
     *     tags={"Check-in"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"ids"},
     *
     *             @OA\Property(property="ids", type="array", @OA\Items(type="integer"), description="Every tracker id of the user, in the new order")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Trackers reordered successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/CheckInTracker"))
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function reorder(ReorderCheckInTrackersRequest $request): JsonResponse
    {
        return ApiResponse::success(
            CheckInTrackerResource::collection($this->trackerService->reorder($request->user(), $request->validated('ids'))),
            'Trackers reordered successfully.'
        );
    }

    /**
     * @OA\Put(
     *     path="/check-in/trackers/{tracker}",
     *     operationId="checkInTrackerUpdate",
     *     summary="Update a tracker; built-in trackers only take enabled and scored",
     *     tags={"Check-in"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="tracker", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="name", type="string", maxLength=50),
     *             @OA\Property(property="icon", type="string", maxLength=64, nullable=true),
     *             @OA\Property(property="enabled", type="boolean"),
     *             @OA\Property(property="scored", type="boolean")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Tracker updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/CheckInTracker")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateCheckInTrackerRequest $request, CheckInTracker $tracker): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $tracker), 403);

        return ApiResponse::success(
            new CheckInTrackerResource($this->trackerService->update($tracker, $request->validated())),
            'Tracker updated successfully.'
        );
    }

    /**
     * @OA\Delete(
     *     path="/check-in/trackers/{tracker}",
     *     operationId="checkInTrackerDestroy",
     *     summary="Delete an own tracker with all its values; built-in trackers can only be disabled",
     *     tags={"Check-in"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="tracker", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Tracker deleted successfully", @OA\JsonContent(ref="#/components/schemas/SuccessResponse")),
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Built-in tracker", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, CheckInTracker $tracker): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $tracker), 403);

        if ($this->trackerService->isBuiltIn($tracker)) {
            return ApiResponse::error('Built-in trackers can only be disabled.', 422);
        }

        $this->trackerService->delete($tracker);

        return ApiResponse::success(null, 'Tracker deleted successfully.');
    }

    /**
     * @OA\Put(
     *     path="/check-in/trackers/{tracker}/goal",
     *     operationId="checkInTrackerSetGoal",
     *     summary="Set the tracker's goal",
     *     tags={"Check-in"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="tracker", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"period", "comparison", "target"},
     *
     *             @OA\Property(property="period", type="string", enum={"day", "week", "month"}),
     *             @OA\Property(property="comparison", type="string", enum={"at_least", "at_most"}),
     *             @OA\Property(property="target", type="number")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Goal saved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/CheckInTracker")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function setGoal(SetCheckInGoalRequest $request, CheckInTracker $tracker): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $tracker), 403);

        return ApiResponse::success(
            new CheckInTrackerResource($this->trackerService->setGoal($tracker, $request->validated())),
            'Goal saved successfully.'
        );
    }

    /**
     * @OA\Delete(
     *     path="/check-in/trackers/{tracker}/goal",
     *     operationId="checkInTrackerDeleteGoal",
     *     summary="Remove the tracker's goal",
     *     tags={"Check-in"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="tracker", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Goal removed successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/CheckInTracker")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function deleteGoal(Request $request, CheckInTracker $tracker): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $tracker), 403);

        return ApiResponse::success(
            new CheckInTrackerResource($this->trackerService->deleteGoal($tracker)),
            'Goal removed successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/check-in/trackers/{tracker}/alerts",
     *     operationId="checkInTrackerStoreAlert",
     *     summary="Add a reminder to the tracker",
     *     tags={"Check-in"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="tracker", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"time"},
     *
     *             @OA\Property(property="time", type="string", example="20:00"),
     *             @OA\Property(property="target", type="integer", description="Required for count trackers, not allowed otherwise")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Reminder added successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/CheckInTracker")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function storeAlert(CheckInAlertRequest $request, CheckInTracker $tracker): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $tracker), 403);

        return ApiResponse::success(
            new CheckInTrackerResource($this->trackerService->addAlert($tracker, $request->validated())),
            'Reminder added successfully.',
            201
        );
    }

    /**
     * @OA\Put(
     *     path="/check-in/alerts/{alert}",
     *     operationId="checkInTrackerUpdateAlert",
     *     summary="Change a reminder",
     *     tags={"Check-in"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="alert", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"time"},
     *
     *             @OA\Property(property="time", type="string", example="20:00"),
     *             @OA\Property(property="target", type="integer", description="Required for count trackers, not allowed otherwise")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Reminder updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/CheckInTracker")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function updateAlert(CheckInAlertRequest $request, CheckInAlert $alert): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $alert->tracker), 403);

        return ApiResponse::success(
            new CheckInTrackerResource($this->trackerService->updateAlert($alert, $request->validated())),
            'Reminder updated successfully.'
        );
    }

    /**
     * @OA\Delete(
     *     path="/check-in/alerts/{alert}",
     *     operationId="checkInTrackerDeleteAlert",
     *     summary="Remove a reminder",
     *     tags={"Check-in"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="alert", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Reminder removed successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/CheckInTracker")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function deleteAlert(Request $request, CheckInAlert $alert): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $alert->tracker), 403);

        return ApiResponse::success(
            new CheckInTrackerResource($this->trackerService->deleteAlert($alert)),
            'Reminder removed successfully.'
        );
    }
}
