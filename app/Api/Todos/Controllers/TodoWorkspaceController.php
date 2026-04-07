<?php

namespace App\Api\Todos\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Todos\Models\TodoWorkspace;
use App\Api\Todos\Requests\StoreTodoWorkspaceRequest;
use App\Api\Todos\Requests\UpdateTodoWorkspaceRequest;
use App\Api\Todos\Resources\TodoWorkspaceResource;
use App\Api\Todos\Services\TodoWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TodoWorkspaceController
{
    use HandlesApiAuth;

    public function __construct(private readonly TodoWorkspaceService $todoWorkspaceService) {}

    /**
     * @OA\Get(
     *     path="/v1/todos/workspaces",
     *     operationId="todoWorkspaceIndex",
     *     summary="List todo workspaces",
     *     tags={"Todos"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Workspaces retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/TodoWorkspace"))
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            TodoWorkspaceResource::collection($this->todoWorkspaceService->list($request->user())),
            'Workspaces retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/v1/todos/workspaces/{workspace}",
     *     operationId="todoWorkspaceShow",
     *     summary="Get todo workspace by ID",
     *     tags={"Todos"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="workspace",
     *         in="path",
     *         description="Workspace ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Workspace retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TodoWorkspace")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, TodoWorkspace $workspace): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $workspace), 403);

        $workspace = $this->todoWorkspaceService->find($workspace);

        return ApiResponse::success(new TodoWorkspaceResource($workspace), 'Workspace retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/v1/todos/workspaces",
     *     operationId="todoWorkspaceStore",
     *     summary="Create a new todo workspace",
     *     tags={"Todos"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"title"},
     *
     *             @OA\Property(property="title", type="string", maxLength=50),
     *             @OA\Property(property="categories", type="array", @OA\Items(type="integer"))
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Workspace created successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TodoWorkspace")
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function store(StoreTodoWorkspaceRequest $request): JsonResponse
    {
        $workspace = $this->todoWorkspaceService->create($request->user(), $request->validated());

        return ApiResponse::success(new TodoWorkspaceResource($workspace), 'Workspace created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/v1/todos/workspaces/{workspace}",
     *     operationId="todoWorkspaceUpdate",
     *     summary="Update a todo workspace",
     *     tags={"Todos"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="workspace",
     *         in="path",
     *         description="Workspace ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="is_hideable", type="boolean")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Workspace updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TodoWorkspace")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function update(UpdateTodoWorkspaceRequest $request, TodoWorkspace $workspace): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $workspace), 403);

        $workspace = $this->todoWorkspaceService->update($workspace, $request->validated());

        return ApiResponse::success(new TodoWorkspaceResource($workspace), 'Workspace updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/v1/todos/workspaces/{workspace}",
     *     operationId="todoWorkspaceDestroy",
     *     summary="Delete a todo workspace",
     *     tags={"Todos"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="workspace",
     *         in="path",
     *         description="Workspace ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Workspace deleted successfully",
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
    public function destroy(Request $request, TodoWorkspace $workspace): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $workspace), 403);

        $this->todoWorkspaceService->destroy($workspace);

        return ApiResponse::success(null, 'Workspace deleted successfully.');
    }

    /**
     * @OA\Post(
     *     path="/v1/todos/workspaces/{workspace}/categories/attach",
     *     operationId="todoWorkspaceAttachCategories",
     *     summary="Attach categories to a todo workspace",
     *     tags={"Todos"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="workspace",
     *         in="path",
     *         description="Workspace ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"categories"},
     *
     *             @OA\Property(
     *                 property="categories",
     *                 type="array",
     *
     *                 @OA\Items(type="integer"),
     *                 description="Array of category IDs to attach"
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Categories attached successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TodoWorkspace")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function attachCategories(Request $request, TodoWorkspace $workspace): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $workspace), 403);

        $validatedData = $request->validate([
            'categories' => 'required|array',
            'categories.*' => 'exists:todo_categories,id',
        ]);

        $workspace = $this->todoWorkspaceService->attachCategories($workspace, $validatedData['categories']);

        return ApiResponse::success(new TodoWorkspaceResource($workspace), 'Categories attached successfully.');
    }

    /**
     * @OA\Post(
     *     path="/v1/todos/workspaces/{workspace}/categories/detach",
     *     operationId="todoWorkspaceDetachCategories",
     *     summary="Detach categories from a todo workspace",
     *     tags={"Todos"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="workspace",
     *         in="path",
     *         description="Workspace ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"categories"},
     *
     *             @OA\Property(
     *                 property="categories",
     *                 type="array",
     *
     *                 @OA\Items(type="integer"),
     *                 description="Array of category IDs to detach"
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Categories detached successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TodoWorkspace")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function detachCategories(Request $request, TodoWorkspace $workspace): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $workspace), 403);

        $validatedData = $request->validate([
            'categories' => 'required|array',
            'categories.*' => 'exists:todo_categories,id',
        ]);

        $workspace = $this->todoWorkspaceService->detachCategories($workspace, $validatedData['categories']);

        return ApiResponse::success(new TodoWorkspaceResource($workspace), 'Categories detached successfully.');
    }
}
