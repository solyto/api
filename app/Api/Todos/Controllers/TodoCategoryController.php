<?php

namespace App\Api\Todos\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Todos\Models\TodoCategory;
use App\Api\Todos\Requests\StoreTodoCategoryRequest;
use App\Api\Todos\Requests\UpdateTodoCategoryRequest;
use App\Api\Todos\Resources\TodoCategoryResource;
use App\Api\Todos\Services\TodoCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TodoCategoryController
{
    use HandlesApiAuth;

    public function __construct(private readonly TodoCategoryService $todoCategoryService) {}

    /**
     * @OA\Get(
     *     path="/v1/todos/categories",
     *     operationId="todoCategoryIndex",
     *     summary="List todo categories",
     *     tags={"Todos"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Categories retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/TodoCategory"))
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            TodoCategoryResource::collection($this->todoCategoryService->list($request->user())),
            'Categories retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/v1/todos/categories/{category}",
     *     operationId="todoCategoryShow",
     *     summary="Get todo category by ID",
     *     tags={"Todos"},
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
     *             @OA\Property(property="data", ref="#/components/schemas/TodoCategory")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, TodoCategory $category): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $category), 403);

        return ApiResponse::success(new TodoCategoryResource($category), 'Category retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/v1/todos/categories",
     *     operationId="todoCategoryStore",
     *     summary="Create a new todo category",
     *     tags={"Todos"},
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
     *         description="Category created successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TodoCategory")
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function store(StoreTodoCategoryRequest $request): JsonResponse
    {
        $category = $this->todoCategoryService->create($request->user(), $request->validated());

        return ApiResponse::success(new TodoCategoryResource($category), 'Category created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/v1/todos/categories/{category}",
     *     operationId="todoCategoryUpdate",
     *     summary="Update a todo category",
     *     tags={"Todos"},
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
     *             @OA\Property(property="data", ref="#/components/schemas/TodoCategory")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function update(UpdateTodoCategoryRequest $request, TodoCategory $category): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $category), 403);

        $category = $this->todoCategoryService->update($category, $request->validated());

        return ApiResponse::success(new TodoCategoryResource($category), 'Category updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/v1/todos/categories/{category}",
     *     operationId="todoCategoryDestroy",
     *     summary="Delete a todo category",
     *     tags={"Todos"},
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
    public function destroy(Request $request, TodoCategory $category): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $category), 403);

        $this->todoCategoryService->destroy($category);

        return ApiResponse::success(null, 'Category deleted successfully.');
    }
}
