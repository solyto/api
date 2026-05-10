<?php

namespace App\Api\QuickAdd\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\QuickAdd\Requests\DetectRequest;
use App\Api\QuickAdd\Resources\QuickAddResource;
use App\Api\QuickAdd\Services\QuickAddService;
use Illuminate\Http\JsonResponse;

class QuickAddController
{
    use HandlesApiAuth;

    public function __construct(private readonly QuickAddService $quickAddService) {}

    public function detect(DetectRequest $request): JsonResponse
    {
        $result = $this->quickAddService->detect($request->validated('url'));

        return ApiResponse::success(
            new QuickAddResource($result),
            'Content detected successfully.'
        );
    }
}
