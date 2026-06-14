<?php

namespace App\Api\Libraries\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Libraries\Models\LibraryMovie;
use App\Api\Libraries\Requests\Movies\StoreLibraryMovieRequest;
use App\Api\Libraries\Requests\Movies\UpdateLibraryMovieRequest;
use App\Api\Libraries\Resources\ImdbMovieImportResource;
use App\Api\Libraries\Resources\LibraryMovieReleaseResource;
use App\Api\Libraries\Resources\LibraryMovieResource;
use App\Api\Libraries\Services\LibraryMovieService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibraryMovieController
{
    use HandlesApiAuth;

    public function __construct(private readonly LibraryMovieService $libraryMovieService) {}

    /**
     * @OA\Get(
     *     path="/api/libraries/movies",
     *     operationId="listLibraryMovies",
     *     tags={"Libraries - Movies"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Movies retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Movies retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LibraryMovie"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            LibraryMovieResource::collection($this->libraryMovieService->list($request->user())),
            'Movies retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/libraries/movies/{movie}",
     *     operationId="showLibraryMovie",
     *     tags={"Libraries - Movies"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="movie",
     *         in="path",
     *         required=true,
     *         description="Movie ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Movie retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Movie retrieved successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryMovie")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Movie not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, LibraryMovie $movie): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $movie), 403);

        $movie = $this->libraryMovieService->find($movie);

        return ApiResponse::success(new LibraryMovieResource($movie), 'Movie retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/movies",
     *     operationId="storeLibraryMovie",
     *     tags={"Libraries - Movies"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"title", "category"},
     *
     *             @OA\Property(property="title", type="string", maxLength=255),
     *             @OA\Property(property="rating", type="integer", minimum=1, maximum=5, nullable=true),
     *             @OA\Property(property="cover_path", type="string", format="uri", maxLength=255, nullable=true),
     *             @OA\Property(property="link", type="string", format="uri", maxLength=255, nullable=true),
     *             @OA\Property(property="started_at", type="string", format="date", nullable=true),
     *             @OA\Property(property="finished_at", type="string", format="date", nullable=true),
     *             @OA\Property(property="publication_year", type="integer", nullable=true),
     *             @OA\Property(property="category", type="string", enum={"movie", "series"}),
     *             @OA\Property(property="wishlist", type="boolean", nullable=true),
     *             @OA\Property(property="genres", type="array", items={}, nullable=true),
     *             @OA\Property(property="tags", type="array", items={}, nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Movie created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Movie created successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryMovie")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function store(StoreLibraryMovieRequest $request): JsonResponse
    {
        $movie = $this->libraryMovieService->create($request->user(), $request->validated());

        return ApiResponse::success(new LibraryMovieResource($movie), 'Movie created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/api/libraries/movies/{movie}",
     *     operationId="updateLibraryMovie",
     *     tags={"Libraries - Movies"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="movie",
     *         in="path",
     *         required=true,
     *         description="Movie ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"title", "category"},
     *
     *             @OA\Property(property="title", type="string", maxLength=255),
     *             @OA\Property(property="rating", type="integer", minimum=1, maximum=5, nullable=true),
     *             @OA\Property(property="cover_path", type="string", format="uri", maxLength=255, nullable=true),
     *             @OA\Property(property="link", type="string", format="uri", maxLength=255, nullable=true),
     *             @OA\Property(property="started_at", type="string", format="date", nullable=true),
     *             @OA\Property(property="finished_at", type="string", format="date", nullable=true),
     *             @OA\Property(property="publication_year", type="integer", nullable=true),
     *             @OA\Property(property="category", type="string", enum={"movie", "series"}),
     *             @OA\Property(property="wishlist", type="boolean", nullable=true),
     *             @OA\Property(property="genres", type="array", items={}, nullable=true),
     *             @OA\Property(property="tags", type="array", items={}, nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Movie updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Movie updated successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryMovie")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateLibraryMovieRequest $request, LibraryMovie $movie): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $movie), 403);

        $movie = $this->libraryMovieService->update($movie, $request->validated());

        return ApiResponse::success(new LibraryMovieResource($movie), 'Movie updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/api/libraries/movies/{movie}",
     *     operationId="destroyLibraryMovie",
     *     tags={"Libraries - Movies"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="movie",
     *         in="path",
     *         required=true,
     *         description="Movie ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Movie deleted successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Movie deleted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, LibraryMovie $movie): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $movie), 403);

        $this->libraryMovieService->destroy($movie);

        return ApiResponse::success(null, 'Movie deleted successfully.');
    }

    /**
     * @OA\Get(
     *     path="/api/libraries/movies/search/tmdb/{title}",
     *     operationId="searchMovieOnTmdb",
     *     tags={"Libraries - Movies"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="title",
     *         in="path",
     *         required=true,
     *         description="Movie title to search for",
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Search results retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Search results retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function searchMovieOnTmdb(Request $request, string $title): JsonResponse
    {
        $results = $this->libraryMovieService->searchOnTmdb($title);

        return ApiResponse::success($results, 'Search results retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/movies/import/imdb",
     *     operationId="importMovieFromImdb",
     *     tags={"Libraries - Movies"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"url"},
     *
     *             @OA\Property(property="url", type="string", format="uri", example="https://www.imdb.com/title/tt1234567")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Movie imported successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Movie imported successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/ImdbMovieImport")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Movie not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    /**
     * @OA\Get(
     *     path="/api/libraries/movies/releases",
     *     operationId="listLibraryMovieReleases",
     *     tags={"Libraries - Movies"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Releases retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Releases retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LibraryMovieRelease"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function releases(Request $request): JsonResponse
    {
        $releases = $this->libraryMovieService->releases($request->user());

        return ApiResponse::success(
            LibraryMovieReleaseResource::collection($releases),
            'Releases retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/libraries/movies/{movie}/trailers",
     *     operationId="listLibraryMovieTrailers",
     *     tags={"Libraries - Movies"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="movie",
     *         in="path",
     *         required=true,
     *         description="Movie ID",
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Trailers retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Trailers retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="key", type="string"),
     *                 @OA\Property(property="name", type="string")
     *             ))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function trailers(Request $request, LibraryMovie $movie): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $movie), 403);

        $trailers = $this->libraryMovieService->trailers($movie);

        return ApiResponse::success(
            collect($trailers)->map(fn($t) => ['key' => $t['key'], 'name' => $t['name']])->values(),
            'Trailers retrieved successfully.'
        );
    }

    public function importMovieFromImdb(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => 'required|string|url']);

        $movie = $this->libraryMovieService->importFromImdb($data['url']);

        if (! $movie) {
            return ApiResponse::error('Movie not found.', 404);
        }

        return ApiResponse::success(new ImdbMovieImportResource($movie), 'Movie imported successfully.');
    }
}
