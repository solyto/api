<?php

namespace App\Api\Tables\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Tables\Models\Table;
use App\Api\Tables\Models\TableColumn;
use App\Api\Tables\Requests\ReorderTableColumnsRequest;
use App\Api\Tables\Requests\StoreTableColumnRequest;
use App\Api\Tables\Requests\UpdateTableColumnRequest;
use App\Api\Tables\Resources\TableColumnResource;
use App\Api\Tables\Services\TableColumnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TableColumnController
{
    use HandlesApiAuth;

    public function __construct(private readonly TableColumnService $columnService) {}

    /**
     * @OA\Post(
     *     path="/v1/tables/{table}/columns",
     *     operationId="tableColumnStore",
     *     summary="Add a column to a table",
     *     tags={"Tables"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="table", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name", "type"},
     *
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="type", type="string", enum={"text", "number", "date", "checkbox", "url", "select", "tags", "picture"}),
     *             @OA\Property(property="options", type="array", @OA\Items(type="string"), nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Column created successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TableColumn")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function store(StoreTableColumnRequest $request, Table $table): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $table), 403);

        $column = $this->columnService->create($table, $request->validated());

        return ApiResponse::success(new TableColumnResource($column), 'Column created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/v1/tables/{table}/columns/{column}",
     *     operationId="tableColumnUpdate",
     *     summary="Update a table column",
     *     tags={"Tables"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="table", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="column", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="type", type="string", enum={"text", "number", "date", "checkbox", "url", "select", "tags", "picture"}),
     *             @OA\Property(property="options", type="array", @OA\Items(type="string"), nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Column updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TableColumn")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function update(UpdateTableColumnRequest $request, Table $table, TableColumn $column): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $table), 403);
        abort_unless($column->table_id === $table->id, 404);

        $column = $this->columnService->update($column, $request->validated());

        return ApiResponse::success(new TableColumnResource($column), 'Column updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/v1/tables/{table}/columns/{column}",
     *     operationId="tableColumnDestroy",
     *     summary="Delete a table column",
     *     tags={"Tables"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="table", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="column", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Column deleted successfully",
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
    public function destroy(Request $request, Table $table, TableColumn $column): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $table), 403);
        abort_unless($column->table_id === $table->id, 404);

        $this->columnService->destroy($column);

        return ApiResponse::success(null, 'Column deleted successfully.');
    }

    /**
     * @OA\Put(
     *     path="/v1/tables/{table}/columns/reorder",
     *     operationId="tableColumnReorder",
     *     summary="Reorder a table's columns",
     *     tags={"Tables"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="table", in="path", required=true, @OA\Schema(type="string", format="uuid")),
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
     *         description="Columns reordered successfully",
     *
     *         @OA\JsonContent(ref="#/components/schemas/SuccessResponse")
     *     )
     * )
     */
    public function reorder(ReorderTableColumnsRequest $request, Table $table): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $table), 403);

        $this->columnService->reorder($table, $request->validated('ids'));

        return ApiResponse::success(null, 'Columns reordered successfully.');
    }
}
