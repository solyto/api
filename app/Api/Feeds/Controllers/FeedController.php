<?php

namespace App\Api\Feeds\Controllers;

use App\Api\ApiResponse;
use App\Api\Feeds\Exceptions\FeedException;
use App\Api\Feeds\Models\FeedSubscription;
use App\Api\Feeds\Requests\StoreFeedSubscriptionRequest;
use App\Api\Feeds\Requests\UpdateFeedSubscriptionRequest;
use App\Api\Feeds\Resources\FeedItemResource;
use App\Api\Feeds\Resources\FeedResource;
use App\Api\Feeds\Resources\FeedSubscriptionResource;
use App\Api\Feeds\Services\FeedService;
use App\Api\HandlesApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedController
{
    use HandlesApiAuth;

    public function __construct(private readonly FeedService $feedService) {}

    /**
     * @OA\Get(
     *     path="/api/feeds",
     *     operationId="listFeedSubscriptions",
     *     tags={"Feeds"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Feeds retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Feeds retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/FeedSubscription"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function listSubscriptions(Request $request): JsonResponse
    {
        $feeds = $this->feedService->getUserFeeds($request->user()->id);

        return ApiResponse::success(
            FeedSubscriptionResource::collection($feeds),
            'Feeds retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/feeds/items",
     *     operationId="listFeedItems",
     *     tags={"Feeds"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Feed items retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Feed items retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/FeedItem"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function listItems(Request $request): JsonResponse
    {
        $items = $this->feedService->getAggregatedItems($request->user()->id);

        return ApiResponse::success(
            FeedItemResource::collection($items),
            'Feed items retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/feeds/{feedSubscription}",
     *     operationId="showFeedSubscription",
     *     tags={"Feeds"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="feedSubscription",
     *         in="path",
     *         required=true,
     *         description="Feed Subscription ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Feed retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Feed retrieved successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/FeedSubscription")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Feed not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function showSubscription(Request $request, FeedSubscription $feedSubscription): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $feedSubscription), 403);

        return ApiResponse::success(
            new FeedSubscriptionResource($feedSubscription),
            'Feed retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/feeds/available",
     *     operationId="listAvailableFeeds",
     *     tags={"Feeds"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="offset",
     *         in="query",
     *         required=false,
     *         description="Pagination offset",
     *
     *         @OA\Schema(type="integer", default=0)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Feeds retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Feeds retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Feed"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function availableFeeds(Request $request): JsonResponse
    {
        $offset = (int) $request->query('offset', 0);

        return ApiResponse::success(
            FeedResource::collection($this->feedService->getAvailableFeeds($offset)),
            'Feeds retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/api/feeds/search",
     *     operationId="searchFeeds",
     *     tags={"Feeds"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"search"},
     *
     *             @OA\Property(property="search", type="string", minLength=2, maxLength=100, example="tech")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Feeds retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Feeds retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Feed"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function searchFeeds(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => 'required|string|min:2|max:100',
        ]);

        return ApiResponse::success(
            FeedResource::collection($this->feedService->searchFeeds($data['search'])),
            'Feeds retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/feeds/friends",
     *     operationId="listFriendFeeds",
     *     tags={"Feeds"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Friend feeds retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Friend feeds retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function friendFeeds(Request $request): JsonResponse
    {
        $feeds = $this->feedService->getFriendFeeds($request->user()->id);

        return ApiResponse::success($feeds, 'Friend feeds retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/feeds/test",
     *     operationId="testFeed",
     *     tags={"Feeds"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"url"},
     *
     *             @OA\Property(property="url", type="string", format="uri", example="https://example.com/feed.xml")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Feed items retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Feed items retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Feed URL doesn't seem valid", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function testFeed(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => 'required|string|url',
        ]);

        $items = $this->feedService->getTestFeedItems($data['url']);

        if (! $items) {
            return ApiResponse::error('Feed URL doesn\'t seem valid.', 422);
        }

        return ApiResponse::success(
            array_slice($items, 0, 5),
            'Feed items retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/api/feeds",
     *     operationId="storeFeedSubscription",
     *     tags={"Feeds"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"title","url"},
     *
     *             @OA\Property(property="title", type="string"),
     *             @OA\Property(property="url", type="string", format="uri"),
     *             @OA\Property(property="whitelist", type="string", nullable=true),
     *             @OA\Property(property="blacklist", type="string", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Feed created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Feed created successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/FeedSubscription")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=409, description="Already subscribed to this feed", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Feed URL doesn't seem valid", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function storeSubscription(StoreFeedSubscriptionRequest $request): JsonResponse
    {
        $data = $request->validated();
        try {
            $subscription = $this->feedService->createSubscription(
                $request->user(),
                $data['title'],
                $data['url'],
                $data['whitelist'] ?? null,
                $data['blacklist'] ?? null
            );
        } catch (\App\Api\Feeds\Exceptions\FeedAlreadySubscribedException $e) {
            report($e);
            return ApiResponse::error('Already subscribed to this feed.', 409);
        } catch (FeedException $e) {
            report($e);
            return ApiResponse::error('Feed URL doesn\'t seem valid.', 422);
        }

        return ApiResponse::success(
            new FeedSubscriptionResource($subscription),
            'Feed created successfully.',
            201
        );
    }

    /**
     * @OA\Put(
     *     path="/api/feeds/{feedSubscription}",
     *     operationId="updateFeedSubscription",
     *     tags={"Feeds"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="feedSubscription",
     *         in="path",
     *         required=true,
     *         description="Feed Subscription ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             @OA\Property(property="title", type="string"),
     *             @OA\Property(property="whitelist", type="string", nullable=true),
     *             @OA\Property(property="blacklist", type="string", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Feed updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Feed updated successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/FeedSubscription")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function updateSubscription(UpdateFeedSubscriptionRequest $request, FeedSubscription $feedSubscription): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $feedSubscription), 403);

        $feedSubscription = $this->feedService->updateSubscription($feedSubscription, $request->validated());

        return ApiResponse::success(
            new FeedSubscriptionResource($feedSubscription),
            'Feed updated successfully.'
        );
    }

    /**
     * @OA\Delete(
     *     path="/api/feeds/{feedSubscription}",
     *     operationId="destroyFeedSubscription",
     *     tags={"Feeds"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="feedSubscription",
     *         in="path",
     *         required=true,
     *         description="Feed Subscription ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Feed deleted successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Feed deleted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroySubscription(Request $request, FeedSubscription $feedSubscription): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $feedSubscription), 403);

        $this->feedService->deleteSubscription($feedSubscription);

        return ApiResponse::success(null, 'Feed deleted successfully.');
    }
}
