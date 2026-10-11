<?php

namespace App\Api\Planner\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Planner\Models\PlannerEntry;
use App\Api\Planner\Requests\StorePlannerEntryRequest;
use App\Api\Planner\Requests\UpdatePlannerEntryRequest;
use App\Api\Planner\Resources\PlannerEntryResource;
use App\Api\Planner\Services\PlannerEntryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PlannerEntryController
{
    use HandlesApiAuth;

    public function __construct(private readonly PlannerEntryService $plannerEntryService) {}

    /**
     * @OA\Get(
     *     path="/api/planner/entries",
     *     operationId="plannerEntryIndex",
     *     summary="List planner entries in a date range",
     *     tags={"Planner"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="from", in="query", required=true, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="to", in="query", required=true, @OA\Schema(type="string", format="date")),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Planner entries retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/PlannerEntry")),
     *             @OA\Property(property="message", type="string", example="Planner entries retrieved successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ]);

        if (Carbon::parse($validated['from'])->diffInDays(Carbon::parse($validated['to'])) > 366) {
            throw ValidationException::withMessages([
                'to' => 'The date range must not exceed 366 days.',
            ]);
        }

        return ApiResponse::success(
            PlannerEntryResource::collection(
                $this->plannerEntryService->list($request->user(), $validated['from'], $validated['to'])
            ),
            'Planner entries retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/api/planner/entries",
     *     operationId="plannerEntryStore",
     *     tags={"Planner"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"date", "title", "start_minutes", "duration_minutes"},
     *
     *             @OA\Property(property="date", type="string", format="date"),
     *             @OA\Property(property="title", type="string"),
     *             @OA\Property(property="start_minutes", type="integer", minimum=0, maximum=1439),
     *             @OA\Property(property="duration_minutes", type="integer", minimum=1),
     *             @OA\Property(property="is_all_day", type="boolean"),
     *             @OA\Property(property="color", type="string", minLength=7, maxLength=7, nullable=true),
     *             @OA\Property(property="todo_id", type="string", format="uuid", nullable=true),
     *             @OA\Property(property="sort_order", type="integer", minimum=0)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Planner entry created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/PlannerEntry"),
     *             @OA\Property(property="message", type="string", example="Planner entry created successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(StorePlannerEntryRequest $request): JsonResponse
    {
        $entry = $this->plannerEntryService->create($request->user(), $request->validated());

        return ApiResponse::success(new PlannerEntryResource($entry), 'Planner entry created successfully.', 201);
    }

    /**
     * @OA\Patch(
     *     path="/api/planner/entries/{entry}",
     *     operationId="plannerEntryUpdate",
     *     tags={"Planner"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="entry", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="date", type="string", format="date"),
     *             @OA\Property(property="title", type="string"),
     *             @OA\Property(property="start_minutes", type="integer", minimum=0, maximum=1439),
     *             @OA\Property(property="duration_minutes", type="integer", minimum=1),
     *             @OA\Property(property="is_all_day", type="boolean"),
     *             @OA\Property(property="color", type="string", minLength=7, maxLength=7, nullable=true),
     *             @OA\Property(property="todo_id", type="string", format="uuid", nullable=true),
     *             @OA\Property(property="sort_order", type="integer", minimum=0)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Planner entry updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/PlannerEntry"),
     *             @OA\Property(property="message", type="string", example="Planner entry updated successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(UpdatePlannerEntryRequest $request, PlannerEntry $entry): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $entry), 403);

        $entry = $this->plannerEntryService->update($entry, $request->validated());

        return ApiResponse::success(new PlannerEntryResource($entry), 'Planner entry updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/api/planner/entries/{entry}",
     *     operationId="plannerEntryDestroy",
     *     tags={"Planner"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="entry", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Planner entry deleted successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", nullable=true),
     *             @OA\Property(property="message", type="string", example="Planner entry deleted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Unauthorized")
     * )
     */
    public function destroy(Request $request, PlannerEntry $entry): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $entry), 403);

        $this->plannerEntryService->destroy($entry);

        return ApiResponse::success(null, 'Planner entry deleted successfully.');
    }
}
