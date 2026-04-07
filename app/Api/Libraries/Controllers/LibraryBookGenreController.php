<?php

namespace App\Api\Libraries\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Libraries\Models\LibraryBookGenre;
use App\Api\Libraries\Requests\Books\StoreLibraryBookGenreRequest;
use App\Api\Libraries\Requests\Books\UpdateLibraryBookGenreRequest;
use App\Api\Libraries\Resources\LibraryBookGenreResource;
use App\Api\Libraries\Services\LibraryBookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibraryBookGenreController
{
    use HandlesApiAuth;

    public function __construct(private readonly LibraryBookService $libraryBookService) {}

    /**
     * @OA\Get(
     *     path="/api/libraries/books/genres",
     *     operationId="listLibraryBookGenres",
     *     tags={"Libraries - Books"},
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
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LibraryBookGenre"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            LibraryBookGenreResource::collection($this->libraryBookService->listGenres($request->user())),
            'Genres retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/libraries/books/genres/{genre}",
     *     operationId="showLibraryBookGenre",
     *     tags={"Libraries - Books"},
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
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryBookGenre")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Genre not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, LibraryBookGenre $genre): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $genre), 403);

        return ApiResponse::success(new LibraryBookGenreResource($genre), 'Genre retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/books/genres",
     *     operationId="storeLibraryBookGenre",
     *     tags={"Libraries - Books"},
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
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryBookGenre")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function store(StoreLibraryBookGenreRequest $request): JsonResponse
    {
        $genre = $this->libraryBookService->createGenre($request->user(), $request->validated());

        return ApiResponse::success(new LibraryBookGenreResource($genre), 'Genre created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/api/libraries/books/genres/{genre}",
     *     operationId="updateLibraryBookGenre",
     *     tags={"Libraries - Books"},
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
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryBookGenre")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateLibraryBookGenreRequest $request, LibraryBookGenre $genre): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $genre), 403);

        $genre = $this->libraryBookService->updateGenre($genre, $request->validated());

        return ApiResponse::success(new LibraryBookGenreResource($genre), 'Genre updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/api/libraries/books/genres/{genre}",
     *     operationId="destroyLibraryBookGenre",
     *     tags={"Libraries - Books"},
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
    public function destroy(Request $request, LibraryBookGenre $genre): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $genre), 403);

        $this->libraryBookService->destroyGenre($genre);

        return ApiResponse::success(null, 'Genre deleted successfully.');
    }
}
