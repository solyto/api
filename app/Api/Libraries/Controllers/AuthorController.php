<?php

namespace App\Api\Libraries\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Libraries\Models\Author;
use App\Api\Libraries\Requests\Authors\StoreAuthorRequest;
use App\Api\Libraries\Requests\Authors\UpdateAuthorRequest;
use App\Api\Libraries\Requests\Authors\UploadAuthorPhotoRequest;
use App\Api\Libraries\Resources\AuthorResource;
use App\Api\Libraries\Services\LibraryBookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthorController
{
    use HandlesApiAuth;

    public function __construct(private readonly LibraryBookService $libraryBookService) {}

    /**
     * @OA\Get(
     *     path="/api/libraries/books/authors",
     *     operationId="listAuthors",
     *     tags={"Libraries - Books"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Authors retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Authors retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Author"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            AuthorResource::collection($this->libraryBookService->listAuthors($request->user())),
            'Authors retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/libraries/books/authors/{author}",
     *     operationId="showAuthor",
     *     tags={"Libraries - Books"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="author",
     *         in="path",
     *         required=true,
     *         description="Author ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Author retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Author retrieved successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/Author")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Author not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, Author $author): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $author), 403);

        $author = $this->libraryBookService->findAuthor($author);

        return ApiResponse::success(new AuthorResource($author), 'Author retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/books/authors",
     *     operationId="storeAuthor",
     *     tags={"Libraries - Books"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name"},
     *
     *             @OA\Property(property="name", type="string", maxLength=255),
     *             @OA\Property(property="bio", type="string", nullable=true),
     *             @OA\Property(property="hardcover_id", type="integer", nullable=true),
     *             @OA\Property(property="is_favorite", type="boolean", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Author created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Author created successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/Author")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function store(StoreAuthorRequest $request): JsonResponse
    {
        $author = $this->libraryBookService->createAuthor($request->user(), $request->validated());

        return ApiResponse::success(new AuthorResource($author), 'Author created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/api/libraries/books/authors/{author}",
     *     operationId="updateAuthor",
     *     tags={"Libraries - Books"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="author",
     *         in="path",
     *         required=true,
     *         description="Author ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", maxLength=255),
     *             @OA\Property(property="bio", type="string", nullable=true),
     *             @OA\Property(property="hardcover_id", type="integer", nullable=true),
     *             @OA\Property(property="is_favorite", type="boolean", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Author updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Author updated successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/Author")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateAuthorRequest $request, Author $author): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $author), 403);

        $author = $this->libraryBookService->updateAuthor($author, $request->validated());

        return ApiResponse::success(new AuthorResource($author), 'Author updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/api/libraries/books/authors/{author}",
     *     operationId="destroyAuthor",
     *     tags={"Libraries - Books"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="author",
     *         in="path",
     *         required=true,
     *         description="Author ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Author deleted successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Author deleted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, Author $author): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $author), 403);

        $this->libraryBookService->destroyAuthor($author);

        return ApiResponse::success(null, 'Author deleted successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/books/authors/{author}/photo",
     *     operationId="uploadAuthorPhoto",
     *     tags={"Libraries - Books"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="author",
     *         in="path",
     *         required=true,
     *         description="Author ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Photo uploaded successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Photo uploaded successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/Author")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function uploadPhoto(UploadAuthorPhotoRequest $request, Author $author): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $author), 403);

        $author = $this->libraryBookService->uploadAuthorPhoto($author, $request->file('file'));

        if (!$author) {
            return ApiResponse::error('Failed to upload photo.', 422);
        }

        return ApiResponse::success(new AuthorResource($author), 'Photo uploaded successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/books/authors/{author}/resync",
     *     operationId="resyncAuthor",
     *     tags={"Libraries - Books"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="author",
     *         in="path",
     *         required=true,
     *         description="Author ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Author re-synced successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Author re-synced successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/Author")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Author has no linked Hardcover profile, or it could not be found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function resync(Request $request, Author $author): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $author), 403);

        $author = $this->libraryBookService->resyncAuthorFromHardcover($author);

        if (!$author) {
            return ApiResponse::error('Author could not be re-synced from Hardcover.', 404);
        }

        return ApiResponse::success(new AuthorResource($author), 'Author re-synced successfully.');
    }
}
