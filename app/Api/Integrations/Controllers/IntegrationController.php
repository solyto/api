<?php

namespace App\Api\Integrations\Controllers;

use App\Api\ApiResponse;
use App\Api\Integrations\Services\IntegrationService;
use Illuminate\Http\JsonResponse;

class IntegrationController
{
    public function __construct(private readonly IntegrationService $integrationService) {}

    /**
     * @OA\Get(
     *     path="/integrations",
     *     operationId="integrationIndex",
     *     tags={"Integrations"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Integrations retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *
     *                 @OA\Property(property="hardcover", type="boolean"),
     *                 @OA\Property(property="tmdb", type="boolean"),
     *                 @OA\Property(property="discogs", type="boolean"),
     *                 @OA\Property(property="bgg", type="boolean"),
     *                 @OA\Property(property="spotify", type="boolean"),
     *                 @OA\Property(property="telegram", type="boolean"),
     *                 @OA\Property(property="ntfy", type="boolean")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success($this->integrationService->configured(), 'Integrations retrieved successfully.');
    }
}
