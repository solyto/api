<?php

namespace App\Api\TimeTracking\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\TimeTracking\Models\TimeTrackingProject;
use App\Api\TimeTracking\Requests\StoreTimeTrackingEntryRequest;
use App\Api\TimeTracking\Resources\TimeTrackingEntryResource;
use App\Api\TimeTracking\Services\TimeTrackingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimeTrackingEntryController
{
    use HandlesApiAuth;

    public function __construct(private readonly TimeTrackingService $timeTrackingService) {}

    /**
     * @OA\Get(
     *     path="/api/time-tracking-entries",
     *     operationId="timeTrackingEntryIndex",
     *     tags={"Time Tracking"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Time tracking entries retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/TimeTrackingEntry")),
     *             @OA\Property(property="message", type="string", example="Time tracking entries retrieved successfully.")
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            TimeTrackingEntryResource::collection($this->timeTrackingService->listEntries($request->user())),
            'Time tracking entries retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/api/time-tracking-entries",
     *     operationId="timeTrackingEntryStore",
     *     tags={"Time Tracking"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"started_at", "stopped_at", "duration_minutes", "project_id"},
     *
     *             @OA\Property(property="description", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="started_at", type="string", format="date-time"),
     *             @OA\Property(property="stopped_at", type="string", format="date-time"),
     *             @OA\Property(property="duration_minutes", type="integer", minimum=1),
     *             @OA\Property(property="project_id", type="integer")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Time tracking entry created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TimeTrackingEntry"),
     *             @OA\Property(property="message", type="string", example="Time tracking entry created successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function store(StoreTimeTrackingEntryRequest $request): JsonResponse
    {
        $data = $request->validated();

        $project = TimeTrackingProject::find($data['project_id']);
        abort_unless($project && $project->user_id === $request->user()->id, 403);

        $entry = $this->timeTrackingService->createEntry($request->user(), $data);

        return ApiResponse::success(
            new TimeTrackingEntryResource($entry),
            'Time tracking entry created successfully.',
            201
        );
    }

    /**
     * @OA\Put(
     *     path="/api/time-tracking-entries/{entry}",
     *     operationId="timeTrackingEntryUpdate",
     *     tags={"Time Tracking"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="entry",
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
     *             @OA\Property(property="description", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="started_at", type="string", format="date-time"),
     *             @OA\Property(property="stopped_at", type="string", format="date-time", nullable=true),
     *             @OA\Property(property="duration_minutes", type="integer", minimum=0),
     *             @OA\Property(property="project_id", type="integer")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Time tracking entry updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TimeTrackingEntry"),
     *             @OA\Property(property="message", type="string", example="Time tracking entry updated successfully.")
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
    public function update(Request $request, TimeTrackingEntry $entry): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $entry), 403);

        $validated = $request->validate([
            'description' => 'sometimes|nullable|string|max:255',
            'started_at' => 'sometimes|date',
            'stopped_at' => 'sometimes|nullable|date',
            'duration_minutes' => 'sometimes|integer|min:0',
            'project_id' => 'sometimes|exists:time_tracking_projects,id',
        ]);

        if (isset($validated['project_id'])) {
            $project = TimeTrackingProject::find($validated['project_id']);
            abort_unless($project && $project->user_id === $request->user()->id, 403);
        }

        $entry = $this->timeTrackingService->updateEntry($entry, $validated);

        return ApiResponse::success(
            new TimeTrackingEntryResource($entry),
            'Time tracking entry updated successfully.'
        );
    }

    /**
     * @OA\Delete(
     *     path="/api/time-tracking-entries/{entry}",
     *     operationId="timeTrackingEntryDestroy",
     *     tags={"Time Tracking"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="entry",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Time tracking entry deleted successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", nullable=true),
     *             @OA\Property(property="message", type="string", example="Time tracking entry deleted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized"
     *     )
     * )
     */
    public function destroy(Request $request, TimeTrackingEntry $entry): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $entry), 403);

        $this->timeTrackingService->destroyEntry($entry);

        return ApiResponse::success(null, 'Time tracking entry deleted successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/time-tracking-entries/start",
     *     operationId="timeTrackingEntryStart",
     *     tags={"Time Tracking"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"project_id"},
     *
     *             @OA\Property(property="project_id", type="integer"),
     *             @OA\Property(property="description", type="string", maxLength=255, nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Timer started successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TimeTrackingEntry"),
     *             @OA\Property(property="message", type="string", example="Timer started successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="A timer is already running"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:time_tracking_projects,id',
            'description' => 'nullable|string|max:255',
        ]);

        $project = TimeTrackingProject::find($validated['project_id']);
        abort_unless($project && $project->user_id === $request->user()->id, 403);

        if ($this->timeTrackingService->hasRunningTimer($request->user())) {
            return ApiResponse::error('A timer is already running. Stop it first.', 409);
        }

        $entry = $this->timeTrackingService->startTimer($request->user(), $validated);

        return ApiResponse::success(
            new TimeTrackingEntryResource($entry),
            'Timer started successfully.',
            201
        );
    }

    /**
     * @OA\Post(
     *     path="/api/time-tracking-entries/{entry}/stop",
     *     operationId="timeTrackingEntryStop",
     *     tags={"Time Tracking"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="entry",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Timer stopped successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TimeTrackingEntry"),
     *             @OA\Property(property="message", type="string", example="Timer stopped successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Timer already stopped"
     *     )
     * )
     */
    public function stop(Request $request, TimeTrackingEntry $entry): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $entry), 403);

        if ($entry->stopped_at !== null) {
            return ApiResponse::error('This timer has already been stopped.', 409);
        }

        $entry = $this->timeTrackingService->stopTimer($entry);

        return ApiResponse::success(new TimeTrackingEntryResource($entry), 'Timer stopped successfully.');
    }

    /**
     * @OA\Get(
     *     path="/api/time-tracking-entries/statistics",
     *     operationId="timeTrackingEntryStatistics",
     *     tags={"Time Tracking"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="from",
     *         in="query",
     *         required=true,
     *
     *         @OA\Schema(type="string", format="date")
     *     ),
     *
     *     @OA\Parameter(
     *         name="to",
     *         in="query",
     *         required=true,
     *
     *         @OA\Schema(type="string", format="date")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Statistics retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", type="object"),
     *             @OA\Property(property="message", type="string", example="Statistics retrieved successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function statistics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $from = Carbon::parse($validated['from'])->startOfDay();
        $to = Carbon::parse($validated['to'])->endOfDay();

        $stats = $this->timeTrackingService->getStatistics($request->user(), $from, $to);

        return ApiResponse::success(array_merge($stats, [
            'from' => $validated['from'],
            'to' => $validated['to'],
        ]), 'Statistics retrieved successfully.');
    }
}
