<?php

namespace App\Api\CheckIn\Controllers;

use App\Api\ApiResponse;
use App\Api\CheckIn\Requests\StoreCheckInRequest;
use App\Api\CheckIn\Resources\CheckInResource;
use App\Api\CheckIn\Services\CheckInService;
use App\Api\HandlesApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckInController
{
    use HandlesApiAuth;

    public function __construct(private readonly CheckInService $checkInService) {}

    /**
     * @OA\Get(
     *     path="/api/check-ins",
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
     * @OA\Post(
     *     path="/api/check-ins",
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
