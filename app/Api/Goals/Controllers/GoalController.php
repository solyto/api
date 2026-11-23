<?php

namespace App\Api\Goals\Controllers;

use App\Api\ApiResponse;
use App\Api\Goals\Models\Goal;
use App\Api\Goals\Requests\StoreGoalRequest;
use App\Api\Goals\Requests\UpdateGoalRequest;
use App\Api\Goals\Resources\GoalResource;
use App\Api\Goals\Services\GoalProgressService;
use App\Api\Goals\Services\GoalService;
use App\Api\HandlesApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoalController
{
    use HandlesApiAuth;

    public function __construct(
        private readonly GoalService $goalService,
        private readonly GoalProgressService $progressService,
    ) {}

    /**
     * @OA\Get(
     *     path="/goals",
     *     operationId="goalIndex",
     *     summary="List the user's goals with their progress",
     *     tags={"Goals"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Goals retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Goal"))
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $goals = $this->goalService->list($request->user());
        $progress = $this->progressService->progress($request->user(), $goals);

        return ApiResponse::success(
            $goals->map(fn (Goal $goal) => new GoalResource($goal, $progress[$goal->id]))->values(),
            'Goals retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/goals",
     *     operationId="goalStore",
     *     summary="Create a goal",
     *     tags={"Goals"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"source", "period", "comparison", "target"},
     *
     *             @OA\Property(property="source", type="string", enum={"check_in_tracker"}),
     *             @OA\Property(property="check_in_tracker_id", type="integer", description="Required for source check_in_tracker"),
     *             @OA\Property(property="period", type="string", enum={"day", "week", "month"}),
     *             @OA\Property(property="comparison", type="string", enum={"at_least", "at_most"}),
     *             @OA\Property(property="target", type="number"),
     *             @OA\Property(property="notify_when_reached", type="boolean")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Goal created successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Goal")
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error (ValidationErrorResponse), or the goal limit is reached (ErrorResponse, code limit_reached)", @OA\JsonContent(oneOf={@OA\Schema(ref="#/components/schemas/ValidationErrorResponse"), @OA\Schema(ref="#/components/schemas/ErrorResponse")}))
     * )
     */
    public function store(StoreGoalRequest $request): JsonResponse
    {
        $goal = $this->goalService->create($request->user(), $request->validated());

        return ApiResponse::success($this->resource($request, $goal), 'Goal created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/goals/{goal}",
     *     operationId="goalUpdate",
     *     summary="Update a goal; the tracker can't change",
     *     tags={"Goals"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="goal", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="period", type="string", enum={"day", "week", "month"}),
     *             @OA\Property(property="comparison", type="string", enum={"at_least", "at_most"}),
     *             @OA\Property(property="target", type="number"),
     *             @OA\Property(property="notify_when_reached", type="boolean")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Goal updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Goal")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateGoalRequest $request, Goal $goal): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $goal), 403);

        $goal = $this->goalService->update($goal, $request->validated());

        return ApiResponse::success($this->resource($request, $goal), 'Goal updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/goals/{goal}",
     *     operationId="goalDestroy",
     *     summary="Delete a goal",
     *     tags={"Goals"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="goal", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Goal deleted successfully", @OA\JsonContent(ref="#/components/schemas/SuccessResponse")),
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, Goal $goal): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $goal), 403);

        $this->goalService->delete($goal);

        return ApiResponse::success(null, 'Goal deleted successfully.');
    }

    private function resource(Request $request, Goal $goal): GoalResource
    {
        return new GoalResource($goal, $this->progressService->progress($request->user(), collect([$goal]))[$goal->id]);
    }
}
