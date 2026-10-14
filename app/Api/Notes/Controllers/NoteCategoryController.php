<?php

namespace App\Api\Notes\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Notes\Models\NoteCategory;
use App\Api\Notes\Requests\StoreNoteCategoryRequest;
use App\Api\Notes\Requests\UpdateNoteCategoryHiddenRequest;
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
     *     path="/notes/categories",
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
     *     path="/notes/categories/hidden",
     *     operationId="noteCategoryHidden",
     *     summary="List hidden and system note categories",
     *     description="The categories GET /notes/categories leaves out (is_hidden or is_system, and their descendants), as a tree.",
     *     tags={"Notes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Hidden categories retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/NoteCategory"))
     *         )
     *     )
     * )
     */
    public function hidden(Request $request): JsonResponse
    {
        return ApiResponse::success(
            NoteCategoryResource::collection($this->noteCategoryService->listHidden($request->user())),
            'Hidden categories retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/notes/categories/{category}",
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
     *     path="/notes/categories",
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
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function store(StoreNoteCategoryRequest $request): JsonResponse
    {
        $category = $this->noteCategoryService->create($request->user(), $request->validated());

        return ApiResponse::success(new NoteCategoryResource($category), 'Category created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/notes/categories/{category}",
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
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateNoteCategoryRequest $request, NoteCategory $category): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $category), 403);
        abort_if($category->is_system, 403, 'System categories cannot be modified.');

        $category = $this->noteCategoryService->update($category, $request->validated());

        return ApiResponse::success(new NoteCategoryResource($category), 'Category updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/notes/categories/{category}",
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
        abort_if($category->is_system, 403, 'System categories cannot be deleted.');

        $this->noteCategoryService->destroy($category);

        return ApiResponse::success(null, 'Category deleted successfully.');
    }

    /**
     * @OA\Patch(
     *     path="/notes/categories/{category}/hidden",
     *     operationId="noteCategoryUpdateHidden",
     *     summary="Toggle the hidden state of a note category",
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
     *             required={"hidden"},
     *
     *             @OA\Property(property="hidden", type="boolean")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Category visibility updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/NoteCategory")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function updateHidden(UpdateNoteCategoryHiddenRequest $request, NoteCategory $category): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $category), 403);

        $category = $this->noteCategoryService->updateHidden($category, $request->boolean('hidden'));

        return ApiResponse::success(new NoteCategoryResource($category), 'Category visibility updated successfully.');
    }
}
