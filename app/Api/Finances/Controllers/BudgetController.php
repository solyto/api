<?php

namespace App\Api\Finances\Controllers;

use App\Api\ApiResponse;
use App\Api\Finances\Models\Budget;
use App\Api\Finances\Requests\StoreBudgetRequest;
use App\Api\Finances\Requests\UpdateBudgetRequest;
use App\Api\Finances\Resources\BudgetResource;
use App\Api\Finances\Services\BudgetService;
use App\Api\HandlesApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetController
{
    use HandlesApiAuth;

    public function __construct(private readonly BudgetService $budgetService) {}

    /**
     * @OA\Get(
     *     path="/api/finances/budgets",
     *     operationId="listBudgets",
     *     tags={"Finances"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Budgets retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Budgets retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Budget"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            BudgetResource::collection($this->budgetService->list($request->user())),
            'Budgets retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/finances/budgets/{budget}",
     *     operationId="showBudget",
     *     tags={"Finances"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="budget",
     *         in="path",
     *         required=true,
     *         description="Budget ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Budget retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Budget retrieved successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/Budget")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Budget not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, Budget $budget): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $budget), 403);

        return ApiResponse::success(
            new BudgetResource($budget),
            'Budget retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/api/finances/budgets",
     *     operationId="storeBudget",
     *     tags={"Finances"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"title","type","value"},
     *
     *             @OA\Property(property="title", type="string", maxLength=255),
     *             @OA\Property(property="type", type="string", enum={"income","expense"}),
     *             @OA\Property(property="value", type="number")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Budget created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Budget created successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/Budget")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function store(StoreBudgetRequest $request): JsonResponse
    {
        $budget = $this->budgetService->create($request->user(), $request->validated());

        return ApiResponse::success(
            new BudgetResource($budget),
            'Budget created successfully.',
            201
        );
    }

    /**
     * @OA\Put(
     *     path="/api/finances/budgets/{budget}",
     *     operationId="updateBudget",
     *     tags={"Finances"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="budget",
     *         in="path",
     *         required=true,
     *         description="Budget ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             @OA\Property(property="title", type="string", maxLength=255),
     *             @OA\Property(property="type", type="string", enum={"income","expense"}),
     *             @OA\Property(property="value", type="number")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Budget updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Budget updated successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/Budget")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateBudgetRequest $request, Budget $budget): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $budget), 403);

        $budget = $this->budgetService->update($budget, $request->validated());

        return ApiResponse::success(
            new BudgetResource($budget),
            'Budget updated successfully.'
        );
    }

    /**
     * @OA\Delete(
     *     path="/api/finances/budgets/{budget}",
     *     operationId="destroyBudget",
     *     tags={"Finances"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="budget",
     *         in="path",
     *         required=true,
     *         description="Budget ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Budget deleted successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Budget deleted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, Budget $budget): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $budget), 403);

        $this->budgetService->destroy($budget);

        return ApiResponse::success(null, 'Budget deleted successfully.');
    }
}
