<?php

namespace App\Api\CheckIn\Controllers;

use App\Api\ApiResponse;
use App\Api\CheckIn\Requests\StoreCheckInRequest;
use App\Api\CheckIn\Resources\CheckInResource;
use App\Api\CheckIn\Services\CheckInService;
use App\Api\HandlesApiAuth;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CheckInController
{
    use HandlesApiAuth;

    public function __construct(private readonly CheckInService $checkInService) {}

    /**
     * @OA\Get(
     *     path="/check-ins",
     *     operationId="checkInIndex",
     *     tags={"Check-In"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Check-in data retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/CheckIn")),
     *             @OA\Property(property="message", type="string", example="Check-in data retrieved successfully.")
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
    public function marks(Request $request): JsonResponse
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
            $this->checkInService->marks($request->user(), $validated['from'], $validated['to']),
            'Check-in marks retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/check-ins",
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
     *
     *             @OA\Property(property="data", ref="#/components/schemas/CheckIn"),
     *             @OA\Property(property="message", type="string", example="Check-in data created successfully.")
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
        $checkIn = $this->checkInService->find($request->user(), $data['date']);

        if ($checkIn && ! $this->isResourceOwner($request, $checkIn)) {
            return ApiResponse::forbidden();
        }

        $checkIn = $checkIn ?
            $this->checkInService->update($request->user(), $checkIn, $data) :
            $this->checkInService->create($request->user(), $data);

        return ApiResponse::success(
            new CheckInResource($checkIn),
            'Check-in data created successfully.',
            201
        );
    }
}
