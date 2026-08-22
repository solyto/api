<?php

namespace App\Api\Tables\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Tables\Models\Table;
use App\Api\Tables\Models\TableRow;
use App\Api\Tables\Requests\ReorderTableRowsRequest;
use App\Api\Tables\Requests\StoreTableRowRequest;
use App\Api\Tables\Requests\UpdateTableRowRequest;
use App\Api\Tables\Resources\TableRowResource;
use App\Api\Tables\Services\TableRowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TableRowController
{
    use HandlesApiAuth;

    public function __construct(private readonly TableRowService $rowService) {}

    /**
     * @OA\Post(
     *     path="/v1/tables/{table}/rows",
     *     operationId="tableRowStore",
     *     summary="Add a row to a table",
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
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Row created successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TableRow")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function store(StoreTableRowRequest $request, Table $table): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $table), 403);

        $row = $this->rowService->create($table, $request->validated());

        return ApiResponse::success(new TableRowResource($row), 'Row created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/v1/tables/{table}/rows/{row}",
     *     operationId="tableRowUpdate",
     *     summary="Update a table row",
     *     tags={"Tables"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="table", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="row", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Row updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/TableRow")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function update(UpdateTableRowRequest $request, Table $table, TableRow $row): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $table), 403);
        abort_unless($row->table_id === $table->id, 404);

        $row = $this->rowService->update($row, $request->validated());

        return ApiResponse::success(new TableRowResource($row), 'Row updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/v1/tables/{table}/rows/{row}",
     *     operationId="tableRowDestroy",
     *     summary="Delete a table row",
     *     tags={"Tables"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="table", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="row", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Row deleted successfully",
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
    public function destroy(Request $request, Table $table, TableRow $row): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $table), 403);
        abort_unless($row->table_id === $table->id, 404);

        $this->rowService->destroy($row);

        return ApiResponse::success(null, 'Row deleted successfully.');
    }

    /**
     * @OA\Put(
     *     path="/v1/tables/{table}/rows/reorder",
     *     operationId="tableRowReorder",
     *     summary="Reorder a table's rows",
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
     *         description="Rows reordered successfully",
     *
     *         @OA\JsonContent(ref="#/components/schemas/SuccessResponse")
     *     )
     * )
     */
    public function reorder(ReorderTableRowsRequest $request, Table $table): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $table), 403);

        $this->rowService->reorder($table, $request->validated('ids'));

        return ApiResponse::success(null, 'Rows reordered successfully.');
    }
}
