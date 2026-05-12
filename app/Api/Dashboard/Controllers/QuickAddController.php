<?php

namespace App\Api\Dashboard\Controllers;

use App\Api\ApiResponse;
use App\Api\Dashboard\Enums\QuickAddContentType;
use App\Api\Dashboard\Requests\CommitRequest;
use App\Api\Dashboard\Requests\DetectRequest;
use App\Api\Dashboard\Resources\QuickAddResource;
use App\Api\Dashboard\Services\QuickAddService;
use App\Api\HandlesApiAuth;
use Illuminate\Http\JsonResponse;

class QuickAddController
{
    use HandlesApiAuth;

    public function __construct(private readonly QuickAddService $quickAddService) {}

    /**
     * @OA\Post(
     *     path="/api/dashboard/quick-add/detect",
     *     operationId="dashboardQuickAddDetect",
     *     tags={"Dashboard"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"url"},
     *
     *             @OA\Property(property="url", type="string", format="uri", maxLength=2048)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Content detected successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/QuickAddDetection"),
     *             @OA\Property(property="message", type="string", example="Content detected successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function detect(DetectRequest $request): JsonResponse
    {
        $result = $this->quickAddService->detect($request->validated('url'));

        return ApiResponse::success(
            new QuickAddResource($result),
            'Content detected successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/api/dashboard/quick-add/commit",
     *     operationId="dashboardQuickAddCommit",
     *     tags={"Dashboard"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"url","content_type"},
     *
     *             @OA\Property(property="url", type="string", format="uri", maxLength=2048),
     *             @OA\Property(property="content_type", type="string", enum={"music","books","movies","games","links","recipes","plants","quotes","todo","note","feed"}),
     *             @OA\Property(property="metadata", type="object", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Content added successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Content added successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function commit(CommitRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->quickAddService->commit(
            $request->user(),
            $validated['url'],
            QuickAddContentType::from($validated['content_type']),
            $validated['metadata'] ?? null,
        );

        return ApiResponse::success($result, 'Content added successfully.');
    }
}
