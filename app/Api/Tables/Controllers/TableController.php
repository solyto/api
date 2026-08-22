<?php

namespace App\Api\Tables\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Tables\Models\Table;
use App\Api\Tables\Requests\ReorderTablesRequest;
use App\Api\Tables\Requests\StoreTableRequest;
use App\Api\Tables\Requests\UpdateTableRequest;
use App\Api\Tables\Resources\TableResource;
use App\Api\Tables\Services\TableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TableController
{
    use HandlesApiAuth;

    public function __construct(private readonly TableService $tableService) {}

    /**
     * @OA\Get(
     *     path="/v1/tables",
     *     operationId="tableIndex",
     *     summary="List tables",
     *     tags={"Tables"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Tables retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Table"))
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            TableResource::collection($this->tableService->list($request->user())),
            'Tables retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/v1/tables/{table}",
     *     operationId="tableShow",
     *     summary="Get table by ID, including its columns and rows",
     *     tags={"Tables"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="table", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Table retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Table")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, Table $table): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $table), 403);

        return ApiResponse::success(new TableResource($this->tableService->find($table)), 'Table retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/v1/tables",
     *     operationId="tableStore",
     *     summary="Create a new table",
     *     tags={"Tables"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name"},
     *
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="icon", type="string", nullable=true),
     *             @OA\Property(property="view", type="string", enum={"list", "card"})
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Table created successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Table")
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function store(StoreTableRequest $request): JsonResponse
    {
        $table = $this->tableService->create($request->user(), $request->validated());

        return ApiResponse::success(new TableResource($table), 'Table created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/v1/tables/{table}",
     *     operationId="tableUpdate",
     *     summary="Update a table",
     *     tags={"Tables"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="table", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="icon", type="string", nullable=true),
     *             @OA\Property(property="view", type="string", enum={"list", "card"})
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Table updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Table")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function update(UpdateTableRequest $request, Table $table): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $table), 403);

        $table = $this->tableService->update($table, $request->validated());

        return ApiResponse::success(new TableResource($table), 'Table updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/v1/tables/{table}",
     *     operationId="tableDestroy",
     *     summary="Delete a table",
     *     tags={"Tables"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="table", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Table deleted successfully",
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
    public function destroy(Request $request, Table $table): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $table), 403);

        $this->tableService->destroy($table);

        return ApiResponse::success(null, 'Table deleted successfully.');
    }

    /**
     * @OA\Put(
     *     path="/v1/tables/reorder",
     *     operationId="tableReorder",
     *     summary="Reorder tables",
     *     tags={"Tables"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"ids"},
     *
     *             @OA\Property(property="ids", type="array", @OA\Items(type="string", format="uuid"))
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Tables reordered successfully",
     *
     *         @OA\JsonContent(ref="#/components/schemas/SuccessResponse")
     *     )
     * )
     */
    public function reorder(ReorderTablesRequest $request): JsonResponse
    {
        $this->tableService->reorder($request->user(), $request->validated('ids'));

        return ApiResponse::success(null, 'Tables reordered successfully.');
    }
}
