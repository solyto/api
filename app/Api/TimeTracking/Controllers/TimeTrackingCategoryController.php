<?php

namespace App\Api\TimeTracking\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\TimeTracking\Models\TimeTrackingCategory;
use App\Api\TimeTracking\Requests\StoreTimeTrackingCategoryRequest;
use App\Api\TimeTracking\Requests\UpdateTimeTrackingCategoryRequest;
use App\Api\TimeTracking\Resources\TimeTrackingCategoryResource;
use App\Api\TimeTracking\Services\TimeTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimeTrackingCategoryController
{
    use HandlesApiAuth;

    public function __construct(private readonly TimeTrackingService $timeTrackingService) {}

    /**
     * @OA\Get(
     *     path="/api/time-tracking-categories",
     *     operationId="timeTrackingCategoryIndex",
     *     tags={"Time Tracking"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Time tracking categories retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/TimeTrackingCategory")),
     *             @OA\Property(property="message", type="string", example="Time tracking categories retrieved successfully.")
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            TimeTrackingCategoryResource::collection($this->timeTrackingService->listCategories($request->user())),
            'Time tracking categories retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/api/time-tracking-categories",
     *     operationId="timeTrackingCategoryStore",
     *     tags={"Time Tracking"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"title"},
     *
     *             @OA\Property(property="title", type="string", maxLength=255),
     *             @OA\Property(property="color", type="string", maxLength=7, nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Time tracking category created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TimeTrackingCategory"),
     *             @OA\Property(property="message", type="string", example="Time tracking category created successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function store(StoreTimeTrackingCategoryRequest $request): JsonResponse
    {
        $category = $this->timeTrackingService->createCategory($request->user(), $request->validated());

        return ApiResponse::success(
            new TimeTrackingCategoryResource($category),
            'Time tracking category created successfully.',
            201
        );
    }

    /**
     * @OA\Put(
     *     path="/api/time-tracking-categories/{category}",
     *     operationId="timeTrackingCategoryUpdate",
     *     tags={"Time Tracking"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="category",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="title", type="string", maxLength=255),
     *             @OA\Property(property="color", type="string", maxLength=7, nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Time tracking category updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TimeTrackingCategory"),
     *             @OA\Property(property="message", type="string", example="Time tracking category updated successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function update(UpdateTimeTrackingCategoryRequest $request, TimeTrackingCategory $category): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $category), 403);

        $category = $this->timeTrackingService->updateCategory($category, $request->validated());

        return ApiResponse::success(
            new TimeTrackingCategoryResource($category),
            'Time tracking category updated successfully.'
        );
    }

    /**
     * @OA\Delete(
     *     path="/api/time-tracking-categories/{category}",
     *     operationId="timeTrackingCategoryDestroy",
     *     tags={"Time Tracking"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="category",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Time tracking category deleted successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", nullable=true),
     *             @OA\Property(property="message", type="string", example="Time tracking category deleted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized"
     *     )
     * )
     */
    public function destroy(Request $request, TimeTrackingCategory $category): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $category), 403);

        $this->timeTrackingService->destroyCategory($category);

        return ApiResponse::success(null, 'Time tracking category deleted successfully.');
    }
}
