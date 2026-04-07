<?php

namespace App\Api\Libraries\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Libraries\Models\LibraryGameGenre;
use App\Api\Libraries\Requests\Games\StoreLibraryGameGenreRequest;
use App\Api\Libraries\Requests\Games\UpdateLibraryGameGenreRequest;
use App\Api\Libraries\Resources\LibraryGameGenreResource;
use App\Api\Libraries\Services\LibraryGameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibraryGameGenreController
{
    use HandlesApiAuth;

    public function __construct(private readonly LibraryGameService $libraryGameService) {}

    /**
     * @OA\Get(
     *     path="/api/libraries/games/genres",
     *     operationId="listLibraryGameGenres",
     *     tags={"Libraries - Games"},
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
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LibraryGameGenre"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            LibraryGameGenreResource::collection($this->libraryGameService->listGenres($request->user())),
            'Genres retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/libraries/games/genres/{genre}",
     *     operationId="showLibraryGameGenre",
     *     tags={"Libraries - Games"},
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
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryGameGenre")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Genre not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, LibraryGameGenre $genre): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $genre), 403);

        return ApiResponse::success(new LibraryGameGenreResource($genre), 'Genre retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/games/genres",
     *     operationId="storeLibraryGameGenre",
     *     tags={"Libraries - Games"},
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
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryGameGenre")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function store(StoreLibraryGameGenreRequest $request): JsonResponse
    {
        $genre = $this->libraryGameService->createGenre($request->user(), $request->validated());

        return ApiResponse::success(new LibraryGameGenreResource($genre), 'Genre created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/api/libraries/games/genres/{genre}",
     *     operationId="updateLibraryGameGenre",
     *     tags={"Libraries - Games"},
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
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryGameGenre")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateLibraryGameGenreRequest $request, LibraryGameGenre $genre): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $genre), 403);

        $genre = $this->libraryGameService->updateGenre($genre, $request->validated());

        return ApiResponse::success(new LibraryGameGenreResource($genre), 'Genre updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/api/libraries/games/genres/{genre}",
     *     operationId="destroyLibraryGameGenre",
     *     tags={"Libraries - Games"},
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
    public function destroy(Request $request, LibraryGameGenre $genre): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $genre), 403);

        $this->libraryGameService->destroyGenre($genre);

        return ApiResponse::success(null, 'Genre deleted successfully.');
    }
}
