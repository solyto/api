<?php

namespace App\Api\Libraries\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Libraries\Models\LibraryYoutubeVideo;
use App\Api\Libraries\Requests\Youtube\StoreLibraryYoutubeVideoRequest;
use App\Api\Libraries\Requests\Youtube\UpdateLibraryYoutubeVideoRequest;
use App\Api\Libraries\Resources\LibraryYoutubeVideoResource;
use App\Api\Libraries\Services\LibraryYoutubeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibraryYoutubeVideoController
{
    use HandlesApiAuth;

    public function __construct(private readonly LibraryYoutubeService $libraryYoutubeService) {}

    /**
     * @OA\Get(
     *     path="/api/libraries/youtube",
     *     operationId="listLibraryYoutubeVideos",
     *     tags={"Libraries - YouTube"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Videos retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Videos retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LibraryYoutubeVideo"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            LibraryYoutubeVideoResource::collection($this->libraryYoutubeService->list($request->user())),
            'Videos retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/libraries/youtube/{video}",
     *     operationId="showLibraryYoutubeVideo",
     *     tags={"Libraries - YouTube"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="video",
     *         in="path",
     *         required=true,
     *         description="Video ID",
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Video retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Video retrieved successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryYoutubeVideo")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Video not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, LibraryYoutubeVideo $video): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $video), 403);

        $video = $this->libraryYoutubeService->find($video);

        return ApiResponse::success(new LibraryYoutubeVideoResource($video), 'Video retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/youtube",
     *     operationId="storeLibraryYoutubeVideo",
     *     tags={"Libraries - YouTube"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"url"},
     *
     *             @OA\Property(property="title", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="url", type="string", format="uri"),
     *             @OA\Property(property="is_favorite", type="boolean"),
     *             @OA\Property(property="cover_path", type="string", nullable=true),
     *             @OA\Property(property="category_id", type="integer", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Video created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Video created successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryYoutubeVideo")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function store(StoreLibraryYoutubeVideoRequest $request): JsonResponse
    {
        $video = $this->libraryYoutubeService->create($request->user(), $request->validated());

        return ApiResponse::success(new LibraryYoutubeVideoResource($video), 'Video created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/api/libraries/youtube/{video}",
     *     operationId="updateLibraryYoutubeVideo",
     *     tags={"Libraries - YouTube"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="video",
     *         in="path",
     *         required=true,
     *         description="Video ID",
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="title", type="string", maxLength=255),
     *             @OA\Property(property="url", type="string", format="uri"),
     *             @OA\Property(property="is_favorite", type="boolean"),
     *             @OA\Property(property="cover_path", type="string", nullable=true),
     *             @OA\Property(property="category_id", type="integer", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Video updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Video updated successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryYoutubeVideo")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateLibraryYoutubeVideoRequest $request, LibraryYoutubeVideo $video): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $video), 403);

        $video = $this->libraryYoutubeService->update($video, $request->validated());

        return ApiResponse::success(new LibraryYoutubeVideoResource($video), 'Video updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/api/libraries/youtube/{video}",
     *     operationId="destroyLibraryYoutubeVideo",
     *     tags={"Libraries - YouTube"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="video",
     *         in="path",
     *         required=true,
     *         description="Video ID",
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Video deleted successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Video deleted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, LibraryYoutubeVideo $video): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $video), 403);

        $this->libraryYoutubeService->destroy($video);

        return ApiResponse::success(null, 'Video deleted successfully.');
    }

    /**
     * @OA\Put(
     *     path="/api/libraries/youtube/reorder",
     *     operationId="reorderLibraryYoutubeVideos",
     *     tags={"Libraries - YouTube"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"videos"},
     *
     *             @OA\Property(property="videos", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Videos reordered successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Videos reordered successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function reorder(Request $request): JsonResponse
    {
        $request->validate(['videos' => 'required|array', 'videos.*' => 'string']);

        $this->libraryYoutubeService->reorder($request->user(), $request->input('videos'));

        return ApiResponse::success(null, 'Videos reordered successfully.');
    }
}
