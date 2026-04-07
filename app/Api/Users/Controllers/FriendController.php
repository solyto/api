<?php

namespace App\Api\Users\Controllers;

use App\Api\ApiResponse;
use App\Api\Users\Models\FriendRequest;
use App\Api\Users\Requests\StoreFriendRequestRequest;
use App\Api\Users\Resources\FriendRequestResource;
use App\Api\Users\Resources\FriendResource;
use App\Api\Users\Services\FriendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FriendController
{
    public function __construct(private readonly FriendService $friendService) {}

    /**
     * @OA\Get(
     *     path="/v1/friends",
     *     operationId="friendListFriends",
     *     summary="List friends",
     *     tags={"Friends"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Friends retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Friend"))
     *         )
     *     )
     * )
     */
    public function listFriends(Request $request): JsonResponse
    {
        return ApiResponse::success(
            FriendResource::collection($this->friendService->listFriends($request->user())),
            'Friends retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/v1/friends/requests",
     *     operationId="friendListFriendRequests",
     *     summary="List friend requests",
     *     tags={"Friends"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Friend requests retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/FriendRequest"))
     *         )
     *     )
     * )
     */
    public function listFriendRequests(Request $request): JsonResponse
    {
        return ApiResponse::success(
            FriendRequestResource::collection($this->friendService->listFriendRequests($request->user())),
            'Friend requests retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/v1/friends/requests",
     *     operationId="friendStoreFriendRequest",
     *     summary="Send friend request",
     *     tags={"Friends"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"receiver_id"},
     *
     *             @OA\Property(property="receiver_id", type="integer", description="User ID to send request to")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Friend request sent successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/FriendRequest")
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function storeFriendRequest(StoreFriendRequestRequest $request): JsonResponse
    {
        $friendRequest = $this->friendService->sendFriendRequest($request->user(), $request->validated());

        return ApiResponse::success(
            new FriendRequestResource($friendRequest),
            'Friend request sent successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/v1/friends/requests/{id}/accept",
     *     operationId="friendAcceptFriendRequest",
     *     summary="Accept friend request",
     *     tags={"Friends"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Friend request ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Friend request accepted successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Friend")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden - not the receiver", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Friend request not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function acceptFriendRequest(Request $request, FriendRequest $friendRequest): JsonResponse
    {
        abort_unless($request->user()->id === $friendRequest->receiver_id, 403);

        $friend = $this->friendService->acceptFriendRequest($friendRequest);

        return ApiResponse::success(new FriendResource($friend), 'Friend request accepted successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/v1/friends/requests/{id}",
     *     operationId="friendRejectFriendRequest",
     *     summary="Reject friend request",
     *     tags={"Friends"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Friend request ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Friend request rejected successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden - not the receiver", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Friend request not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function rejectFriendRequest(Request $request, FriendRequest $friendRequest): JsonResponse
    {
        abort_unless($request->user()->id === $friendRequest->receiver_id, 403);

        $this->friendService->rejectFriendRequest($friendRequest);

        return ApiResponse::success(null, 'Friend request rejected successfully.');
    }
}
