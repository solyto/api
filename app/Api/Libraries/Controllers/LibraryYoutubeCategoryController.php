<?php

namespace App\Api\Libraries\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Libraries\Models\LibraryYoutubeCategory;
use App\Api\Libraries\Requests\Youtube\StoreLibraryYoutubeCategoryRequest;
use App\Api\Libraries\Requests\Youtube\UpdateLibraryYoutubeCategoryRequest;
use App\Api\Libraries\Resources\LibraryYoutubeCategoryResource;
use App\Api\Libraries\Services\LibraryYoutubeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibraryYoutubeCategoryController
{
    use HandlesApiAuth;

    public function __construct(private readonly LibraryYoutubeService $libraryYoutubeService) {}

    /**
     * @OA\Get(
     *     path="/api/libraries/youtube/categories",
     *     operationId="listLibraryYoutubeCategories",
     *     tags={"Libraries - YouTube"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Categories retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Categories retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LibraryYoutubeCategory"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            LibraryYoutubeCategoryResource::collection($this->libraryYoutubeService->listCategories($request->user())),
            'Categories retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/youtube/categories",
     *     operationId="storeLibraryYoutubeCategory",
     *     tags={"Libraries - YouTube"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"title"},
     *
     *             @OA\Property(property="title", type="string", maxLength=255),
     *             @OA\Property(property="color", type="string", maxLength=255, nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Category created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Category created successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryYoutubeCategory")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function store(StoreLibraryYoutubeCategoryRequest $request): JsonResponse
    {
        $category = $this->libraryYoutubeService->createCategory($request->user(), $request->validated());

        return ApiResponse::success(new LibraryYoutubeCategoryResource($category), 'Category created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/api/libraries/youtube/categories/{category}",
     *     operationId="updateLibraryYoutubeCategory",
     *     tags={"Libraries - YouTube"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="category",
     *         in="path",
     *         required=true,
     *         description="Category ID",
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
     *             @OA\Property(property="title", type="string", maxLength=255),
     *             @OA\Property(property="color", type="string", maxLength=255, nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Category updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Category updated successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryYoutubeCategory")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateLibraryYoutubeCategoryRequest $request, LibraryYoutubeCategory $category): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $category), 403);

        $category = $this->libraryYoutubeService->updateCategory($category, $request->validated());

        return ApiResponse::success(new LibraryYoutubeCategoryResource($category), 'Category updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/api/libraries/youtube/categories/{category}",
     *     operationId="destroyLibraryYoutubeCategory",
     *     tags={"Libraries - YouTube"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="category",
     *         in="path",
     *         required=true,
     *         description="Category ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Category deleted successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Category deleted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, LibraryYoutubeCategory $category): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $category), 403);

        $this->libraryYoutubeService->destroyCategory($category);

        return ApiResponse::success(null, 'Category deleted successfully.');
    }

    /**
     * @OA\Put(
     *     path="/api/libraries/youtube/categories/reorder",
     *     operationId="reorderLibraryYoutubeCategories",
     *     tags={"Libraries - YouTube"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"categories"},
     *
     *             @OA\Property(property="categories", type="array", @OA\Items(type="integer"))
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Categories reordered successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Categories reordered successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function reorder(Request $request): JsonResponse
    {
        $request->validate(['categories' => 'required|array', 'categories.*' => 'integer']);

        $this->libraryYoutubeService->reorderCategories($request->user(), $request->input('categories'));

        return ApiResponse::success(null, 'Categories reordered successfully.');
    }
}
