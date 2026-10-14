<?php

namespace App\Api\Statistics\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Statistics\Services\StatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatisticsController
{
    use HandlesApiAuth;

    public function __construct(private readonly StatisticsService $statisticsService) {}

    /**
     * @OA\Get(
     *     path="/statistics/overview",
     *     operationId="statisticsOverview",
     *     tags={"Statistics"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Statistics retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized - Admin only"
     *     )
     * )
     */
    public function overview(Request $request): JsonResponse
    {
        abort_unless($this->isAdmin($request), 403);

        return ApiResponse::success(
            $this->statisticsService->overview(),
            'Statistics retrieved successfully.'
        );
    }
}
