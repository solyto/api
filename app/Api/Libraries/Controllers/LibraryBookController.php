<?php

namespace App\Api\Libraries\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Libraries\Enums\LibraryRecommendationEnum;
use App\Api\Libraries\Models\LibraryBook;
use App\Api\Libraries\Requests\Books\StoreLibraryBookRequest;
use App\Api\Libraries\Requests\Books\UpdateLibraryBookRequest;
use App\Api\Libraries\Resources\GoodreadsBookImportResource;
use App\Api\Libraries\Resources\HardcoverBookImportResource;
use App\Api\Libraries\Resources\LibraryBookReleaseResource;
use App\Api\Libraries\Resources\LibraryBookResource;
use App\Api\Libraries\Resources\LibraryRecommendationResource;
use App\Api\Libraries\Services\LibraryBookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibraryBookController
{
    use HandlesApiAuth;

    public function __construct(private readonly LibraryBookService $libraryBookService) {}

    /**
     * @OA\Get(
     *     path="/api/libraries/books",
     *     operationId="listLibraryBooks",
     *     tags={"Libraries - Books"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Books retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Books retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LibraryBook"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            LibraryBookResource::collection($this->libraryBookService->list($request->user())),
            'Books retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/libraries/books/{book}",
     *     operationId="showLibraryBook",
     *     tags={"Libraries - Books"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="book",
     *         in="path",
     *         required=true,
     *         description="Book ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Book retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Book retrieved successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryBook")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Book not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, LibraryBook $book): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $book), 403);

        $book = $this->libraryBookService->find($book);

        return ApiResponse::success(new LibraryBookResource($book), 'Book retrieved successfully.');
    }

    /**
     * @OA\Get(
     *     path="/api/libraries/books/recommend/{type}",
     *     operationId="recommendLibraryBook",
     *     tags={"Libraries - Books"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="type",
     *         in="path",
     *         required=true,
     *         description="Recommendation type",
     *
     *         @OA\Schema(type="string", enum={"random", "favorite", "new"})
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Recommendation retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Recommendation retrieved successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryRecommendation")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="No recommendation found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function recommend(Request $request, LibraryRecommendationEnum $type): JsonResponse
    {
        if ($type === LibraryRecommendationEnum::NEW) {
            $recommendation = $this->libraryBookService->recommendNew($request->user());

            if (! $recommendation) {
                return ApiResponse::error('No recommendation found.', 404);
            }

            return ApiResponse::success(
                new LibraryRecommendationResource($recommendation),
                'Recommendation retrieved successfully.'
            );
        }

        $recommendation = $this->libraryBookService->recommend($request->user(), $type);

        if (! $recommendation) {
            return ApiResponse::error('No recommendation found.', 404);
        }

        return ApiResponse::success(
            new LibraryRecommendationResource($recommendation),
            'Recommendation retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/libraries/books/releases",
     *     operationId="listLibraryBookReleases",
     *     tags={"Libraries - Books"},
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
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LibraryBookRelease"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function releases(Request $request): JsonResponse
    {
        $releases = $this->libraryBookService->releases($request->user());

        return ApiResponse::success(
            LibraryBookReleaseResource::collection($releases),
            'Releases retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/books",
     *     operationId="storeLibraryBook",
     *     tags={"Libraries - Books"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"title", "author"},
     *
     *             @OA\Property(property="title", type="string", maxLength=255),
     *             @OA\Property(property="author", type="string", maxLength=255),
     *             @OA\Property(property="series", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="volume", type="integer", nullable=true),
     *             @OA\Property(property="pages", type="integer", nullable=true),
     *             @OA\Property(property="current_page", type="integer", nullable=true),
     *             @OA\Property(property="rating", type="integer", minimum=1, maximum=5, nullable=true),
     *             @OA\Property(property="lent_to", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="is_where", type="string", nullable=true),
     *             @OA\Property(property="cover_path", type="string", format="uri", maxLength=255, nullable=true),
     *             @OA\Property(property="link", type="string", format="uri", maxLength=255, nullable=true),
     *             @OA\Property(property="started_at", type="string", format="date", nullable=true),
     *             @OA\Property(property="finished_at", type="string", format="date", nullable=true),
     *             @OA\Property(property="publication_year", type="integer", nullable=true),
     *             @OA\Property(property="wishlist", type="boolean", nullable=true),
     *             @OA\Property(property="summary", type="string", nullable=true),
     *             @OA\Property(property="genres", type="array", @OA\Items(type="integer"), nullable=true),
     *             @OA\Property(property="tags", type="array", @OA\Items(type="string"), nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Book created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Book created successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryBook")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function store(StoreLibraryBookRequest $request): JsonResponse
    {
        $book = $this->libraryBookService->create($request->user(), $request->validated());

        return ApiResponse::success(new LibraryBookResource($book), 'Book created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/api/libraries/books/{book}",
     *     operationId="updateLibraryBook",
     *     tags={"Libraries - Books"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="book",
     *         in="path",
     *         required=true,
     *         description="Book ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             @OA\Property(property="title", type="string", maxLength=255),
     *             @OA\Property(property="author", type="string", maxLength=255),
     *             @OA\Property(property="series", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="volume", type="integer", nullable=true),
     *             @OA\Property(property="pages", type="integer", nullable=true),
     *             @OA\Property(property="current_page", type="integer", nullable=true),
     *             @OA\Property(property="rating", type="integer", minimum=1, maximum=5, nullable=true),
     *             @OA\Property(property="lent_to", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="is_where", type="string", nullable=true),
     *             @OA\Property(property="cover_path", type="string", format="uri", maxLength=255, nullable=true),
     *             @OA\Property(property="link", type="string", format="uri", maxLength=255, nullable=true),
     *             @OA\Property(property="started_at", type="string", format="date", nullable=true),
     *             @OA\Property(property="finished_at", type="string", format="date", nullable=true),
     *             @OA\Property(property="publication_year", type="integer", nullable=true),
     *             @OA\Property(property="wishlist", type="boolean", nullable=true),
     *             @OA\Property(property="summary", type="string", nullable=true),
     *             @OA\Property(property="genres", type="array", @OA\Items(type="integer"), nullable=true),
     *             @OA\Property(property="tags", type="array", @OA\Items(type="string"), nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Book updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Book updated successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryBook")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateLibraryBookRequest $request, LibraryBook $book): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $book), 403);

        $book = $this->libraryBookService->update($book, $request->validated());

        return ApiResponse::success(new LibraryBookResource($book), 'Book updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/api/libraries/books/{book}",
     *     operationId="destroyLibraryBook",
     *     tags={"Libraries - Books"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="book",
     *         in="path",
     *         required=true,
     *         description="Book ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Book deleted successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Book deleted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, LibraryBook $book): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $book), 403);

        $this->libraryBookService->destroy($book);

        return ApiResponse::success(null, 'Book deleted successfully.');
    }

    /**
     * @OA\Get(
     *     path="/api/libraries/books/search/hardcover/{title}",
     *     operationId="searchBookOnHardcover",
     *     tags={"Libraries - Books"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="title",
     *         in="path",
     *         required=true,
     *         description="Book title to search for",
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
    public function searchBookOnHardcover(Request $request, string $title): JsonResponse
    {
        $results = $this->libraryBookService->searchOnHardcover($title);

        return ApiResponse::success($results, 'Search results retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/books/import/hardcover",
     *     operationId="importBookFromHardcover",
     *     tags={"Libraries - Books"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"url"},
     *
     *             @OA\Property(property="url", type="string", format="uri", example="https://hardcover.app/book/example")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Book imported successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Book imported successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/HardcoverBookImport")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Book not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function importBookFromHardcover(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => 'required|string|url']);

        $book = $this->libraryBookService->importFromHardcover($data['url']);

        if (! $book) {
            return ApiResponse::error('Book not found.', 404);
        }

        return ApiResponse::success(new HardcoverBookImportResource($book), 'Book imported successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/books/import/goodreads",
     *     operationId="importBookFromGoodreads",
     *     tags={"Libraries - Books"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"url"},
     *
     *             @OA\Property(property="url", type="string", format="uri", example="https://www.goodreads.com/book/show/example")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Book imported successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Book imported successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/GoodreadsBookImport")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Book not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function importBookFromGoodreads(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => 'required|string|url']);

        $book = $this->libraryBookService->importFromGoodreads($data['url']);

        if (! $book) {
            return ApiResponse::error('Book not found.', 404);
        }

        return ApiResponse::success(new GoodreadsBookImportResource($book), 'Book imported successfully.');
    }
}
