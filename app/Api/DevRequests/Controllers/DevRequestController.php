<?php

namespace App\Api\DevRequests\Controllers;

use App\Api\ApiResponse;
use App\Api\DevRequests\Models\DevRequest;
use App\Api\DevRequests\Requests\StoreDevRequestCommentRequest;
use App\Api\DevRequests\Requests\StoreDevRequestRequest;
use App\Api\DevRequests\Requests\UpdateDevRequestRequest;
use App\Api\DevRequests\Resources\DevRequestCommentResource;
use App\Api\DevRequests\Resources\DevRequestResource;
use App\Api\DevRequests\Services\DevRequestService;
use App\Api\HandlesApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DevRequestController
{
    use HandlesApiAuth;

    public function __construct(private readonly DevRequestService $devRequestService) {}

    /**
     * @OA\Get(
     *     path="/api/dev-requests",
     *     operationId="devRequestIndex",
     *     tags={"Dev Requests"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Dev Requests retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/DevRequest")),
     *             @OA\Property(property="message", type="string", example="Dev Requests retrieved successfully.")
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            DevRequestResource::collection($this->devRequestService->list()),
            'Dev Requests retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/api/dev-requests",
     *     operationId="devRequestStore",
     *     tags={"Dev Requests"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"type", "title", "description"},
     *
     *             @OA\Property(property="type", type="string", enum={"feature", "bug"}),
     *             @OA\Property(property="title", type="string", maxLength=255),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="screenshot", type="string", nullable=true),
     *             @OA\Property(property="screenshot_name", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="priority", type="integer", minimum=1, maximum=5, nullable=true),
     *             @OA\Property(property="url", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="created_by_user_id", type="string", format="uuid", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Dev Request created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/DevRequest"),
     *             @OA\Property(property="message", type="string", example="Dev Request created successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function store(StoreDevRequestRequest $request): JsonResponse
    {
        $devRequest = $this->devRequestService->create($request->validated());

        return ApiResponse::success(
            new DevRequestResource($devRequest),
            'Dev Request created successfully.',
            201
        );
    }

    /**
     * @OA\Put(
     *     path="/api/dev-requests/{devRequest}",
     *     operationId="devRequestUpdate",
     *     tags={"Dev Requests"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="devRequest",
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
     *             @OA\Property(property="type", type="string", enum={"feature", "bug"}),
     *             @OA\Property(property="status", type="string", enum={"backlog", "pending", "in-progress", "completed", "cancelled"}),
     *             @OA\Property(property="title", type="string", maxLength=255),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="screenshot", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="priority", type="integer", minimum=1, maximum=5, nullable=true),
     *             @OA\Property(property="url", type="string", maxLength=255, nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Dev Request updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/DevRequest"),
     *             @OA\Property(property="message", type="string", example="Dev Request updated successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized - Admin only"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function update(UpdateDevRequestRequest $request, DevRequest $devRequest): JsonResponse
    {
        abort_unless($this->isAdmin($request), 403);

        $devRequest = $this->devRequestService->update($devRequest, $request->validated());

        return ApiResponse::success(new DevRequestResource($devRequest), 'Dev Request updated successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/dev-requests/{devRequest}/vote",
     *     operationId="devRequestVote",
     *     tags={"Dev Requests"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="devRequest",
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
     *             required={"vote"},
     *
     *             @OA\Property(property="vote", type="integer")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Vote recorded successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/DevRequest"),
     *             @OA\Property(property="message", type="string", example="Vote recorded successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function vote(Request $request, DevRequest $devRequest): JsonResponse
    {
        $devRequest->load(['votes', 'comments']);

        $devRequest = $this->devRequestService->vote($devRequest, $request->user(), $request->input('vote'));

        return ApiResponse::success(new DevRequestResource($devRequest), 'Vote recorded successfully.');
    }

    /**
     * @OA\Get(
     *     path="/api/dev-requests/{devRequest}/comments",
     *     operationId="devRequestListComments",
     *     tags={"Dev Requests"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="devRequest",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Comments retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/DevRequestComment")),
     *             @OA\Property(property="message", type="string", example="Comments retrieved successfully.")
     *         )
     *     )
     * )
     */
    public function listComments(DevRequest $devRequest): JsonResponse
    {
        $comments = $this->devRequestService->listComments($devRequest);

        return ApiResponse::success(
            DevRequestCommentResource::collection($comments),
            'Comments retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/api/dev-requests/{devRequest}/comments",
     *     operationId="devRequestStoreComment",
     *     tags={"Dev Requests"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="devRequest",
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
     *             required={"content"},
     *
     *             @OA\Property(property="content", type="string")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Comment created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/DevRequestComment"),
     *             @OA\Property(property="message", type="string", example="Comment created successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function storeComment(StoreDevRequestCommentRequest $request, DevRequest $devRequest): JsonResponse
    {
        $comment = $this->devRequestService->createComment(
            $devRequest,
            $request->user(),
            $request->validated('content')
        );

        return ApiResponse::success(
            new DevRequestCommentResource($comment),
            'Comment created successfully.',
            201
        );
    }
}
