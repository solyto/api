<?php

namespace App\Api\Alerts\Controllers;

use App\Api\Alerts\Models\Alert;
use App\Api\Alerts\Requests\StoreAlertRequest;
use App\Api\Alerts\Requests\UpdateAlertRequest;
use App\Api\Alerts\Resources\AlertResource;
use App\Api\Alerts\Services\AlertService;
use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController
{
    use HandlesApiAuth;

    public function __construct(private readonly AlertService $alertService) {}

    /**
     * @OA\Get(
     *     path="/alerts",
     *     operationId="alertIndex",
     *     summary="List the user's alerts with what each rule currently sees",
     *     tags={"Alerts"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Alerts retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Alert"))
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $alerts = $this->alertService->list($request->user());
        $states = $this->alertService->states($request->user(), $alerts);

        return ApiResponse::success(
            $alerts->map(fn (Alert $alert) => new AlertResource($alert, $states[$alert->id]))->values(),
            'Alerts retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/alerts",
     *     operationId="alertStore",
     *     summary="Create an alert on a tracker",
     *     tags={"Alerts"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"source", "check_in_tracker_id", "rule", "days"},
     *
     *             @OA\Property(property="source", type="string", enum={"check_in_tracker"}),
     *             @OA\Property(property="check_in_tracker_id", type="integer"),
     *             @OA\Property(property="rule", type="string", enum={"average_below", "average_above", "no_entry_days", "entry_days"}),
     *             @OA\Property(property="threshold", type="number", nullable=true, description="Required for average rules"),
     *             @OA\Property(property="days", type="integer", minimum=1, maximum=90)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Alert created successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Alert")
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function store(StoreAlertRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->resource($request, $this->alertService->create($request->user(), $request->validated())),
            'Alert created successfully.',
            201
        );
    }

    /**
     * @OA\Put(
     *     path="/alerts/{alert}",
     *     operationId="alertUpdate",
     *     summary="Update an alert; goal alerts only take enabled",
     *     tags={"Alerts"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="alert", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="rule", type="string", enum={"average_below", "average_above", "no_entry_days", "entry_days"}),
     *             @OA\Property(property="threshold", type="number", nullable=true),
     *             @OA\Property(property="days", type="integer", minimum=1, maximum=90),
     *             @OA\Property(property="enabled", type="boolean")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Alert updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Alert")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateAlertRequest $request, Alert $alert): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $alert), 403);

        return ApiResponse::success(
            $this->resource($request, $this->alertService->update($alert, $request->validated())),
            'Alert updated successfully.'
        );
    }

    /**
     * @OA\Delete(
     *     path="/alerts/{alert}",
     *     operationId="alertDestroy",
     *     summary="Delete an alert",
     *     tags={"Alerts"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="alert", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Alert deleted successfully", @OA\JsonContent(ref="#/components/schemas/SuccessResponse")),
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, Alert $alert): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $alert), 403);

        $this->alertService->delete($alert);

        return ApiResponse::success(null, 'Alert deleted successfully.');
    }

    /**
     * @OA\Get(
     *     path="/alerts/suggestions",
     *     operationId="alertSuggestions",
     *     summary="Ready-made alerts the user can switch on: only for enabled trackers and not yet taken",
     *     tags={"Alerts"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Alert suggestions retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="object",
     *
     *                     @OA\Property(property="key", type="string", enum={"no_sport_week", "mood_trending_down", "sleep_trending_down", "drinking_more"}),
     *                     @OA\Property(property="check_in_tracker_id", type="integer"),
     *                     @OA\Property(property="rule", type="string"),
     *                     @OA\Property(property="threshold", type="number", nullable=true),
     *                     @OA\Property(property="days", type="integer")
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function suggestions(Request $request): JsonResponse
    {
        return ApiResponse::success($this->alertService->suggestions($request->user()), 'Alert suggestions retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/alerts/suggestions/{key}",
     *     operationId="alertEnableSuggestion",
     *     summary="Switch on a suggested alert",
     *     tags={"Alerts"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="key", in="path", required=true, @OA\Schema(type="string")),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Alert created successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Alert")
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Suggestion not available", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function enableSuggestion(Request $request, string $key): JsonResponse
    {
        return ApiResponse::success(
            $this->resource($request, $this->alertService->enableSuggestion($request->user(), $key)),
            'Alert created successfully.',
            201
        );
    }

    private function resource(Request $request, Alert $alert): AlertResource
    {
        return new AlertResource($alert, $this->alertService->states($request->user(), collect([$alert]))[$alert->id]);
    }
}
