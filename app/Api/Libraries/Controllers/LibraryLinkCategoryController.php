<?php

namespace App\Api\Libraries\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Libraries\Models\LibraryLinkCategory;
use App\Api\Libraries\Requests\Links\StoreLibraryLinkCategoryRequest;
use App\Api\Libraries\Requests\Links\UpdateLibraryLinkCategoryRequest;
use App\Api\Libraries\Resources\LibraryLinkCategoryResource;
use App\Api\Libraries\Services\LibraryLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibraryLinkCategoryController
{
    use HandlesApiAuth;

    public function __construct(private readonly LibraryLinkService $libraryLinkService) {}

    /**
     * @OA\Get(
     *     path="/api/libraries/links/categories",
     *     operationId="listLibraryLinkCategories",
     *     tags={"Libraries - Links"},
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
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LibraryLinkCategory"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            LibraryLinkCategoryResource::collection($this->libraryLinkService->listCategories($request->user())),
            'Categories retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/links/categories",
     *     operationId="storeLibraryLinkCategory",
     *     tags={"Libraries - Links"},
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
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryLinkCategory")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function store(StoreLibraryLinkCategoryRequest $request): JsonResponse
    {
        $category = $this->libraryLinkService->createCategory($request->user(), $request->validated());

        return ApiResponse::success(new LibraryLinkCategoryResource($category), 'Category created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/api/libraries/links/categories/{category}",
     *     operationId="updateLibraryLinkCategory",
     *     tags={"Libraries - Links"},
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
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryLinkCategory")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateLibraryLinkCategoryRequest $request, LibraryLinkCategory $category): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $category), 403);

        $category = $this->libraryLinkService->updateCategory($category, $request->validated());

        return ApiResponse::success(new LibraryLinkCategoryResource($category), 'Category updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/api/libraries/links/categories/{category}",
     *     operationId="destroyLibraryLinkCategory",
     *     tags={"Libraries - Links"},
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
    public function destroy(Request $request, LibraryLinkCategory $category): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $category), 403);

        $this->libraryLinkService->destroyCategory($category);

        return ApiResponse::success(null, 'Category deleted successfully.');
    }
}
