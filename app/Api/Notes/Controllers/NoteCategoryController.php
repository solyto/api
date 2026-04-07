<?php

namespace App\Api\Notes\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Notes\Models\NoteCategory;
use App\Api\Notes\Requests\StoreNoteCategoryRequest;
use App\Api\Notes\Requests\UpdateNoteCategoryRequest;
use App\Api\Notes\Resources\NoteCategoryResource;
use App\Api\Notes\Services\NoteCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NoteCategoryController
{
    use HandlesApiAuth;

    public function __construct(private readonly NoteCategoryService $noteCategoryService) {}

    /**
     * @OA\Get(
     *     path="/v1/notes/categories",
     *     operationId="noteCategoryIndex",
     *     summary="List note categories",
     *     tags={"Notes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Categories retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/NoteCategory"))
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            NoteCategoryResource::collection($this->noteCategoryService->listRoots($request->user())),
            'Categories retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/v1/notes/categories/{category}",
     *     operationId="noteCategoryShow",
     *     summary="Get note category by ID",
     *     tags={"Notes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="category",
     *         in="path",
     *         description="Category ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Category retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/NoteCategory")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, NoteCategory $category): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $category), 403);

        return ApiResponse::success(new NoteCategoryResource($category), 'Category retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/v1/notes/categories",
     *     operationId="noteCategoryStore",
     *     summary="Create a new note category",
     *     tags={"Notes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"title"},
     *
     *             @OA\Property(property="title", type="string"),
     *             @OA\Property(property="parent_id", type="integer", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Category created successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/NoteCategory")
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function store(StoreNoteCategoryRequest $request): JsonResponse
    {
        $category = $this->noteCategoryService->create($request->user(), $request->validated());

        return ApiResponse::success(new NoteCategoryResource($category), 'Category created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/v1/notes/categories/{category}",
     *     operationId="noteCategoryUpdate",
     *     summary="Update a note category",
     *     tags={"Notes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="category",
     *         in="path",
     *         description="Category ID",
     *         required=true,
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
     *             @OA\Property(property="title", type="string")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Category updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/NoteCategory")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function update(UpdateNoteCategoryRequest $request, NoteCategory $category): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $category), 403);

        $category = $this->noteCategoryService->update($category, $request->validated());

        return ApiResponse::success(new NoteCategoryResource($category), 'Category updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/v1/notes/categories/{category}",
     *     operationId="noteCategoryDestroy",
     *     summary="Delete a note category",
     *     tags={"Notes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="category",
     *         in="path",
     *         description="Category ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Category deleted successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, NoteCategory $category): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $category), 403);

        $this->noteCategoryService->destroy($category);

        return ApiResponse::success(null, 'Category deleted successfully.');
    }
}
