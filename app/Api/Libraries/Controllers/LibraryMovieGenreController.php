<?php

namespace App\Api\Libraries\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Libraries\Models\LibraryMovieGenre;
use App\Api\Libraries\Requests\Movies\StoreLibraryMovieGenreRequest;
use App\Api\Libraries\Requests\Movies\UpdateLibraryMovieGenreRequest;
use App\Api\Libraries\Resources\LibraryMovieGenreResource;
use App\Api\Libraries\Services\LibraryMovieService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibraryMovieGenreController
{
    use HandlesApiAuth;

    public function __construct(private readonly LibraryMovieService $libraryMovieService) {}

    /**
     * @OA\Get(
     *     path="/api/libraries/movies/genres",
     *     operationId="listLibraryMovieGenres",
     *     tags={"Libraries - Movies"},
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
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LibraryMovieGenre"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            LibraryMovieGenreResource::collection($this->libraryMovieService->listGenres($request->user())),
            'Genres retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/libraries/movies/genres/{genre}",
     *     operationId="showLibraryMovieGenre",
     *     tags={"Libraries - Movies"},
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
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryMovieGenre")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Genre not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, LibraryMovieGenre $genre): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $genre), 403);

        return ApiResponse::success(new LibraryMovieGenreResource($genre), 'Genre retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/movies/genres",
     *     operationId="storeLibraryMovieGenre",
     *     tags={"Libraries - Movies"},
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
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryMovieGenre")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function store(StoreLibraryMovieGenreRequest $request): JsonResponse
    {
        $genre = $this->libraryMovieService->createGenre($request->user(), $request->validated());

        return ApiResponse::success(new LibraryMovieGenreResource($genre), 'Genre created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/api/libraries/movies/genres/{genre}",
     *     operationId="updateLibraryMovieGenre",
     *     tags={"Libraries - Movies"},
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
     *             required={"title"},
     *
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
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryMovieGenre")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateLibraryMovieGenreRequest $request, LibraryMovieGenre $genre): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $genre), 403);

        $genre = $this->libraryMovieService->updateGenre($genre, $request->validated());

        return ApiResponse::success(new LibraryMovieGenreResource($genre), 'Genre updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/api/libraries/movies/genres/{genre}",
     *     operationId="destroyLibraryMovieGenre",
     *     tags={"Libraries - Movies"},
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
    public function destroy(Request $request, LibraryMovieGenre $genre): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $genre), 403);

        $this->libraryMovieService->destroyGenre($genre);

        return ApiResponse::success(null, 'Genre deleted successfully.');
    }
}
