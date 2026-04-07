<?php

namespace App\Api\Todos\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Todos\Models\Todo;
use App\Api\Todos\Models\TodoSubtask;
use App\Api\Todos\Requests\StoreTodoRequest;
use App\Api\Todos\Requests\UpdateTodoRequest;
use App\Api\Todos\Resources\TodoResource;
use App\Api\Todos\Resources\TodoSubtaskResource;
use App\Api\Todos\Services\TodoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TodoController
{
    use HandlesApiAuth;

    public function __construct(private readonly TodoService $todoService) {}

    /**
     * @OA\Get(
     *     path="/v1/todos",
     *     operationId="todoIndex",
     *     summary="List todos",
     *     tags={"Todos"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Todos retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Todo"))
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            TodoResource::collection($this->todoService->list($request->user())),
            'Todos retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/v1/todos/due-date",
     *     operationId="todoListDueDate",
     *     summary="List todos by due date",
     *     tags={"Todos"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Todos retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Todo"))
     *         )
     *     )
     * )
     */
    public function listDueDate(Request $request): JsonResponse
    {
        return ApiResponse::success(
            TodoResource::collection($this->todoService->listDueDate($request->user())),
            'Todos retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/v1/todos/{todo}",
     *     operationId="todoShow",
     *     summary="Get todo by ID",
     *     tags={"Todos"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="todo",
     *         in="path",
     *         description="Todo ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Todo retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Todo")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Todo not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, Todo $todo): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $todo), 403);

        $todo = $this->todoService->find($todo);

        return ApiResponse::success(new TodoResource($todo), 'Todo retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/v1/todos",
     *     operationId="todoStore",
     *     summary="Create a new todo",
     *     tags={"Todos"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"title"},
     *
     *             @OA\Property(property="title", type="string", maxLength=255),
     *             @OA\Property(property="description", type="string", maxLength=1000, nullable=true),
     *             @OA\Property(property="priority", type="string", enum={"low","medium","high"}),
     *             @OA\Property(property="due_at", type="string", format="date-time", nullable=true),
     *             @OA\Property(property="status", type="string", enum={"backlog","pending","in-progress","waiting","almost-done"}),
     *             @OA\Property(property="progress", type="integer", minimum=0, maximum=100, nullable=true),
     *             @OA\Property(property="effort", type="string", enum={"low","medium","high"}, nullable=true),
     *             @OA\Property(property="category_id", type="integer", nullable=true),
     *             @OA\Property(property="tags", type="array", @OA\Items(type="integer"), nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Todo created successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Todo")
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function store(StoreTodoRequest $request): JsonResponse
    {
        $todo = $this->todoService->create($request->user(), $request->validated());

        return ApiResponse::success(new TodoResource($todo), 'Todo created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/v1/todos/{todo}",
     *     operationId="todoUpdate",
     *     summary="Update a todo",
     *     tags={"Todos"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="todo",
     *         in="path",
     *         description="Todo ID",
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
     *             @OA\Property(property="title", type="string", maxLength=255),
     *             @OA\Property(property="description", type="string", maxLength=1000, nullable=true),
     *             @OA\Property(property="priority", type="string", enum={"low","medium","high"}),
     *             @OA\Property(property="due_at", type="string", format="date-time", nullable=true),
     *             @OA\Property(property="status", type="string", enum={"backlog","pending","in-progress","waiting","almost-done"}),
     *             @OA\Property(property="progress", type="integer", minimum=0, maximum=100, nullable=true),
     *             @OA\Property(property="effort", type="string", enum={"low","medium","high"}, nullable=true),
     *             @OA\Property(property="is_completed", type="boolean"),
     *             @OA\Property(property="category_id", type="integer", nullable=true),
     *             @OA\Property(property="tags", type="array", @OA\Items(type="integer"), nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Todo updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Todo")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function update(UpdateTodoRequest $request, Todo $todo): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $todo), 403);

        $todo = $this->todoService->update($todo, $request->validated());

        return ApiResponse::success(new TodoResource($todo), 'Todo updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/v1/todos/{todo}",
     *     operationId="todoDestroy",
     *     summary="Delete a todo",
     *     tags={"Todos"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="todo",
     *         in="path",
     *         description="Todo ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Todo deleted successfully",
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
    public function destroy(Request $request, Todo $todo): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $todo), 403);

        $this->todoService->destroy($todo);

        return ApiResponse::success(null, 'Todo deleted successfully.');
    }

    /**
     * @OA\Post(
     *     path="/v1/todos/{todo}/subtasks",
     *     operationId="todoAddSubtask",
     *     summary="Add a subtask to a todo",
     *     tags={"Todos"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="todo",
     *         in="path",
     *         description="Todo ID",
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
     *         description="Subtask added successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TodoSubtask")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function addSubtask(Request $request, Todo $todo): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $todo), 403);

        $validatedData = $request->validate(['title' => 'required|string']);

        $subtask = $this->todoService->addSubtask($todo, $validatedData['title']);

        return ApiResponse::success(new TodoSubtaskResource($subtask), 'Subtask added successfully.');
    }

    /**
     * @OA\Put(
     *     path="/v1/todos/{todo}/subtasks/{subtask}",
     *     operationId="todoUpdateSubtask",
     *     summary="Update a subtask",
     *     tags={"Todos"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="todo",
     *         in="path",
     *         description="Todo ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Parameter(
     *         name="subtask",
     *         in="path",
     *         description="Subtask ID",
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
     *             @OA\Property(property="title", type="string"),
     *             @OA\Property(property="is_completed", type="boolean")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Subtask updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TodoSubtask")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function updateSubtask(Request $request, Todo $todo, TodoSubtask $subtask): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $todo), 403);

        $validatedData = $request->validate([
            'title' => 'sometimes|string',
            'is_completed' => 'sometimes|boolean',
        ]);

        $subtask = $this->todoService->updateSubtask($subtask, $validatedData);

        return ApiResponse::success(new TodoSubtaskResource($subtask), 'Subtask updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/v1/todos/{todo}/subtasks/{subtask}",
     *     operationId="todoDestroySubtask",
     *     summary="Delete a subtask",
     *     tags={"Todos"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="todo",
     *         in="path",
     *         description="Todo ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Parameter(
     *         name="subtask",
     *         in="path",
     *         description="Subtask ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Subtask deleted successfully",
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
    public function destroySubtask(Request $request, Todo $todo, TodoSubtask $subtask): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $todo), 403);

        $this->todoService->destroySubtask($subtask);

        return ApiResponse::success(null, 'Subtask deleted successfully.');
    }
}
