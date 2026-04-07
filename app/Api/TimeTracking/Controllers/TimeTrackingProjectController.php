<?php

namespace App\Api\TimeTracking\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\TimeTracking\Models\TimeTrackingProject;
use App\Api\TimeTracking\Requests\StoreTimeTrackingProjectRequest;
use App\Api\TimeTracking\Requests\UpdateTimeTrackingProjectRequest;
use App\Api\TimeTracking\Resources\TimeTrackingProjectResource;
use App\Api\TimeTracking\Services\TimeTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimeTrackingProjectController
{
    use HandlesApiAuth;

    public function __construct(private readonly TimeTrackingService $timeTrackingService) {}

    /**
     * @OA\Get(
     *     path="/api/time-tracking-projects",
     *     operationId="timeTrackingProjectIndex",
     *     tags={"Time Tracking"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Time tracking projects retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/TimeTrackingProject")),
     *             @OA\Property(property="message", type="string", example="Time tracking projects retrieved successfully.")
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            TimeTrackingProjectResource::collection($this->timeTrackingService->listProjects($request->user())),
            'Time tracking projects retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/time-tracking-projects/{project}",
     *     operationId="timeTrackingProjectShow",
     *     tags={"Time Tracking"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="project",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Time tracking project retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TimeTrackingProject"),
     *             @OA\Property(property="message", type="string", example="Time tracking project retrieved successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized"
     *     )
     * )
     */
    public function show(Request $request, TimeTrackingProject $project): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $project), 403);

        $project = $this->timeTrackingService->findProject($project);

        return ApiResponse::success(
            new TimeTrackingProjectResource($project),
            'Time tracking project retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/api/time-tracking-projects",
     *     operationId="timeTrackingProjectStore",
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
     *             @OA\Property(property="description", type="string", maxLength=1000, nullable=true),
     *             @OA\Property(property="category_ids", type="array", @OA\Items(type="integer"), nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Time tracking project created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TimeTrackingProject"),
     *             @OA\Property(property="message", type="string", example="Time tracking project created successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function store(StoreTimeTrackingProjectRequest $request): JsonResponse
    {
        $project = $this->timeTrackingService->createProject($request->user(), $request->validated());

        return ApiResponse::success(
            new TimeTrackingProjectResource($project),
            'Time tracking project created successfully.',
            201
        );
    }

    /**
     * @OA\Put(
     *     path="/api/time-tracking-projects/{project}",
     *     operationId="timeTrackingProjectUpdate",
     *     tags={"Time Tracking"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="project",
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
     *             @OA\Property(property="description", type="string", maxLength=1000, nullable=true),
     *             @OA\Property(property="category_ids", type="array", @OA\Items(type="integer"), nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Time tracking project updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TimeTrackingProject"),
     *             @OA\Property(property="message", type="string", example="Time tracking project updated successfully.")
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
    public function update(UpdateTimeTrackingProjectRequest $request, TimeTrackingProject $project): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $project), 403);

        $project = $this->timeTrackingService->updateProject($project, $request->validated());

        return ApiResponse::success(
            new TimeTrackingProjectResource($project),
            'Time tracking project updated successfully.'
        );
    }

    /**
     * @OA\Delete(
     *     path="/api/time-tracking-projects/{project}",
     *     operationId="timeTrackingProjectDestroy",
     *     tags={"Time Tracking"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="project",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Time tracking project deleted successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", nullable=true),
     *             @OA\Property(property="message", type="string", example="Time tracking project deleted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized"
     *     )
     * )
     */
    public function destroy(Request $request, TimeTrackingProject $project): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $project), 403);

        $this->timeTrackingService->destroyProject($project);

        return ApiResponse::success(null, 'Time tracking project deleted successfully.');
    }
}
