<?php

namespace App\Api\CheckIn\Controllers;

use App\Api\ApiResponse;
use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\CheckIn\Requests\ListCheckInMarksRequest;
use App\Api\CheckIn\Requests\ListCheckInValuesRequest;
use App\Api\CheckIn\Requests\SetCheckInValueRequest;
use App\Api\CheckIn\Requests\StoreCheckInRequest;
use App\Api\CheckIn\Resources\CheckInResource;
use App\Api\CheckIn\Resources\CheckInValueResource;
use App\Api\CheckIn\Services\CheckInService;
use Illuminate\Support\Arr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckInController
{
    public function __construct(private readonly CheckInService $checkInService) {}

    /**
     * @OA\Get(
     *     path="/check-in",
     *     operationId="checkInIndex",
     *     tags={"Check-In"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Check-in data retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/CheckIn"))
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            CheckInResource::collection($this->checkInService->list($request->user())),
            'Check-in data retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/check-in/marks",
     *     operationId="checkInMarks",
     *     summary="List dates with a check-in",
     *     tags={"Check-In"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="from", in="query", required=true, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="to", in="query", required=true, @OA\Schema(type="string", format="date")),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Check-in marks retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(type="string", format="date"))
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function marks(ListCheckInMarksRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->checkInService->marks($request->user(), $request->validated('from'), $request->validated('to')),
            'Check-in marks retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/check-in",
     *     operationId="checkInStore",
     *     tags={"Check-In"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"date"},
     *
     *             @OA\Property(property="date", type="string", format="date"),
     *             @OA\Property(property="mood", type="integer", enum={1,2,3,4,5}, nullable=true),
     *             @OA\Property(property="water", type="integer", enum={1,2,3,4,5}, nullable=true),
     *             @OA\Property(property="sleep", type="integer", enum={1,2,3,4,5}, nullable=true),
     *             @OA\Property(property="dreams", type="integer", enum={1,2,3,4,5}, nullable=true),
     *             @OA\Property(property="work", type="integer", enum={1,2,3,4,5}, nullable=true),
     *             @OA\Property(property="sports", type="integer", enum={1,2,3,4,5,6}, nullable=true),
     *             @OA\Property(property="food_quality", type="integer", enum={1,2,3,4,5}, nullable=true),
     *             @OA\Property(property="food_amount", type="integer", enum={1,2,3,4,5}, nullable=true),
     *             @OA\Property(property="menstruation", type="integer", enum={1,2,3,4,5}, nullable=true),
     *             @OA\Property(property="alcohol", type="integer", enum={1,2,3,4,5}, nullable=true),
     *             @OA\Property(property="smoking", type="integer", enum={1,2,3,4,5}, nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Check-in data created successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/CheckIn")
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
    public function store(StoreCheckInRequest $request): JsonResponse
    {
        $data = $request->validated();
        $checkIn = $this->checkInService->saveDay($request->user(), $data['date'], Arr::except($data, 'date'));

        return ApiResponse::success(
            new CheckInResource($checkIn),
            'Check-in data created successfully.',
            201
        );
    }

    /**
     * @OA\Get(
     *     path="/check-in/values",
     *     operationId="checkInValuesIndex",
     *     summary="List the values of all of the user's trackers, optionally within a date range",
     *     tags={"Check-in"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="from", in="query", required=false, description="First day, inclusive", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="to", in="query", required=false, description="Last day, inclusive", @OA\Schema(type="string", format="date")),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Check-in values retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/CheckInValue"))
     *         )
     *     )
     * )
     */
    public function values(ListCheckInValuesRequest $request): JsonResponse
    {
        $values = $this->checkInService->values($request->user(), $request->validated('from'), $request->validated('to'));

        return ApiResponse::success(
            CheckInValueResource::collection($values),
            'Check-in values retrieved successfully.'
        );
    }

    /**
     * @OA\Put(
     *     path="/check-in/values",
     *     operationId="checkInValuesSet",
     *     summary="Set or clear one tracker's value on one day",
     *     tags={"Check-in"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"tracker_id", "date", "value"},
     *
     *             @OA\Property(property="tracker_id", type="integer"),
     *             @OA\Property(property="date", type="string", format="date"),
     *             @OA\Property(property="value", type="integer", nullable=true, description="null clears the value")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Check-in value saved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/CheckInValue", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function setValue(SetCheckInValueRequest $request): JsonResponse
    {
        $data = $request->validated();
        $tracker = CheckInTracker::forUser($request->user()->id)->findOrFail($data['tracker_id']);
        $value = $this->checkInService->setValue($request->user(), $tracker, $data['date'], $data['value']);

        return ApiResponse::success(
            $value ? new CheckInValueResource($value) : null,
            'Check-in value saved successfully.'
        );
    }
}
