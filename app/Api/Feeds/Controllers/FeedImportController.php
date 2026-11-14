<?php

namespace App\Api\Feeds\Controllers;

use App\Api\ApiResponse;
use App\Api\Feeds\DTOs\FeedImportStateDTO;
use App\Api\Feeds\Exceptions\FeedImportConflictException;
use App\Api\Feeds\Exceptions\FeedImportException;
use App\Api\Feeds\Exceptions\FeedImportNotFoundException;
use App\Api\Feeds\Requests\ImportFeedsRequest;
use App\Api\Feeds\Requests\SelectImportFeedsRequest;
use App\Api\Feeds\Resources\FeedImportStateResource;
use App\Api\Feeds\Services\OpmlImportService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedImportController
{
    public function __construct(private readonly OpmlImportService $importService) {}

    /**
     * @OA\Post(
     *     path="/feeds/import",
     *     operationId="feedImportStart",
     *     tags={"Feeds"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *
     *             @OA\Schema(
     *                 required={"file"},
     *
     *                 @OA\Property(property="file", type="string", format="binary", description="OPML file (.opml or .xml), at most 1 MB")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Feed import file read successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/FeedImportState")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=409, description="A feed import is already running", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error (ValidationErrorResponse), or the file is not readable OPML, has no feeds or more than 500 feeds (ErrorResponse)",
     *
     *         @OA\JsonContent(oneOf={@OA\Schema(ref="#/components/schemas/ValidationErrorResponse"), @OA\Schema(ref="#/components/schemas/ErrorResponse")})
     *     ),
     *
     *     @OA\Response(response=429, description="Too many requests", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function start(ImportFeedsRequest $request): JsonResponse
    {
        return $this->respond(
            fn () => $this->importService->start($request->user(), $request->file('file')->get()),
            'Feed import file read successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/feeds/import/select",
     *     operationId="feedImportSelect",
     *     tags={"Feeds"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"urls"},
     *
     *             @OA\Property(property="urls", type="array", minItems=1, maxItems=500, @OA\Items(type="string"), example={"https://example.com/feed.xml"})
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Feed import started successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/FeedImportState")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="No feed import found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=409, description="The feed import has already started", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error (ValidationErrorResponse), or none of the selected feeds can be imported (ErrorResponse)",
     *
     *         @OA\JsonContent(oneOf={@OA\Schema(ref="#/components/schemas/ValidationErrorResponse"), @OA\Schema(ref="#/components/schemas/ErrorResponse")})
     *     )
     * )
     */
    public function select(SelectImportFeedsRequest $request): JsonResponse
    {
        return $this->respond(
            fn () => $this->importService->select($request->user(), $request->validated()['urls']),
            'Feed import started successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/feeds/import/state",
     *     operationId="feedImportState",
     *     tags={"Feeds"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Feed import state retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/FeedImportState")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="No feed import found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function state(Request $request): JsonResponse
    {
        return $this->respond(
            fn () => $this->importService->state($request->user()),
            'Feed import state retrieved successfully.'
        );
    }

    /**
     * @param  Closure(): FeedImportStateDTO  $call
     */
    private function respond(Closure $call, string $message): JsonResponse
    {
        try {
            return ApiResponse::success(new FeedImportStateResource($call()), $message);
        } catch (FeedImportException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (FeedImportConflictException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        } catch (FeedImportNotFoundException $e) {
            return ApiResponse::error($e->getMessage(), 404);
        }
    }
}
