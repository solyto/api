<?php

namespace App\Api\Libraries\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Libraries\Models\LibraryMusicGenre;
use App\Api\Libraries\Requests\Music\StoreLibraryMusicGenreRequest;
use App\Api\Libraries\Requests\Music\UpdateLibraryMusicGenreRequest;
use App\Api\Libraries\Resources\LibraryMusicGenreResource;
use App\Api\Libraries\Services\LibraryMusicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibraryMusicGenreController
{
    use HandlesApiAuth;

    public function __construct(private readonly LibraryMusicService $libraryMusicService) {}

    /**
     * @OA\Get(
     *     path="/api/libraries/music/genres",
     *     operationId="listLibraryMusicGenres",
     *     tags={"Libraries - Music"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Genres retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Genres retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LibraryMusicGenre"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            LibraryMusicGenreResource::collection($this->libraryMusicService->listGenres($request->user())),
            'Genres retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/libraries/music/genres/{genre}",
     *     operationId="showLibraryMusicGenre",
     *     tags={"Libraries - Music"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="genre",
     *         in="path",
     *         required=true,
     *         description="Genre ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Genre retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Genre retrieved successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryMusicGenre")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Genre not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, LibraryMusicGenre $genre): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $genre), 403);

        return ApiResponse::success(new LibraryMusicGenreResource($genre), 'Genre retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/music/genres",
     *     operationId="storeLibraryMusicGenre",
     *     tags={"Libraries - Music"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"title"},
     *
     *             @OA\Property(property="title", type="string", maxLength=50)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Genre created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Genre created successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryMusicGenre")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function store(StoreLibraryMusicGenreRequest $request): JsonResponse
    {
        $genre = $this->libraryMusicService->createGenre($request->user(), $request->validated());

        return ApiResponse::success(new LibraryMusicGenreResource($genre), 'Genre created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/api/libraries/music/genres/{genre}",
     *     operationId="updateLibraryMusicGenre",
     *     tags={"Libraries - Music"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="genre",
     *         in="path",
     *         required=true,
     *         description="Genre ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             @OA\Property(property="title", type="string", maxLength=50)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Genre updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Genre updated successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryMusicGenre")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateLibraryMusicGenreRequest $request, LibraryMusicGenre $genre): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $genre), 403);

        $genre = $this->libraryMusicService->updateGenre($genre, $request->validated());

        return ApiResponse::success(new LibraryMusicGenreResource($genre), 'Genre updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/api/libraries/music/genres/{genre}",
     *     operationId="destroyLibraryMusicGenre",
     *     tags={"Libraries - Music"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="genre",
     *         in="path",
     *         required=true,
     *         description="Genre ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Genre deleted successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Genre deleted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, LibraryMusicGenre $genre): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $genre), 403);

        $this->libraryMusicService->destroyGenre($genre);

        return ApiResponse::success(null, 'Genre deleted successfully.');
    }
}
