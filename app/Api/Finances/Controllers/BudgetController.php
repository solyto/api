<?php

namespace App\Api\Finances\Controllers;

use App\Api\ApiResponse;
use App\Api\Finances\Models\Budget;
use App\Api\Finances\Models\BudgetValue;
use App\Api\Finances\Requests\StoreBudgetRequest;
use App\Api\Finances\Requests\StoreBudgetValueRequest;
use App\Api\Finances\Requests\UpdateBudgetRequest;
use App\Api\Finances\Requests\UpdateBudgetValueRequest;
use App\Api\Finances\Resources\BudgetResource;
use App\Api\Finances\Resources\BudgetValueResource;
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
     *     path="/finances/budget",
     *     operationId="listBudgets",
     *     tags={"Finances"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Budgets retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
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
     *     path="/finances/budget/{budget}",
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
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
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
     *     path="/finances/budget",
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
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
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
     *     path="/finances/budget/{budget}",
     *     operationId="updateBudget",
     *     tags={"Finances"},
     *     description="Updates title and type. A given value is recorded as the item's value for today in its value history (replacing an existing value for today), and the item's current value is updated from that history.",
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
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
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
     *     path="/finances/budget/{budget}",
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
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
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
    /**
     * @OA\Get(
     *     path="/finances/budget/{budget}/values",
     *     operationId="listBudgetValues",
     *     tags={"Finances"},
     *     description="Value history of a budget item, ordered by date.",
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
     *         description="Budget values retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/BudgetValue"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Budget not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function listValues(Request $request, Budget $budget): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $budget), 403);

        return ApiResponse::success(
            BudgetValueResource::collection($this->budgetService->listValues($budget)),
            'Budget values retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/finances/budget/{budget}/values",
     *     operationId="storeBudgetValue",
     *     tags={"Finances"},
     *     description="Adds a value to the budget item's history. An item has at most one value per day: if it already has a value on the given date, that value is replaced. The item's current value is updated from the history.",
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
     *             required={"value"},
     *
     *             @OA\Property(property="value", type="number"),
     *             @OA\Property(property="date", type="string", format="date", description="Date of the value (Y-m-d), defaults to today, must not be in the future")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Budget value saved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/BudgetValue")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Budget not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function storeValue(StoreBudgetValueRequest $request, Budget $budget): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $budget), 403);

        $value = $this->budgetService->storeValue($budget, $request->validated());

        return ApiResponse::success(new BudgetValueResource($value), 'Budget value saved successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/finances/budget/{budget}/values/{value}",
     *     operationId="updateBudgetValue",
     *     tags={"Finances"},
     *     description="Corrects the amount and/or the date of a single value. Moving a value to a date that already has a value of this item returns 422. The item's current value is updated from the history.",
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
     *     @OA\Parameter(
     *         name="value",
     *         in="path",
     *         required=true,
     *         description="Budget Value ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             @OA\Property(property="value", type="number"),
     *             @OA\Property(property="date", type="string", format="date", description="Date of the value (Y-m-d), must not be in the future")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Budget value updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/BudgetValue")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Budget or value not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function updateValue(UpdateBudgetValueRequest $request, Budget $budget, BudgetValue $value): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $budget), 403);
        abort_unless($value->budget()->is($budget), 403);

        $value = $this->budgetService->updateValue($budget, $value, $request->validated());

        return ApiResponse::success(new BudgetValueResource($value), 'Budget value updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/finances/budget/{budget}/values/{value}",
     *     operationId="destroyBudgetValue",
     *     tags={"Finances"},
     *     description="Deletes a single value. The last remaining value of a budget item cannot be deleted (422). The item's current value is updated from the history.",
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
     *     @OA\Parameter(
     *         name="value",
     *         in="path",
     *         required=true,
     *         description="Budget Value ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Budget value deleted successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Budget or value not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="The last value of a budget item cannot be deleted", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroyValue(Request $request, Budget $budget, BudgetValue $value): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $budget), 403);
        abort_unless($value->budget()->is($budget), 403);

        $this->budgetService->destroyValue($budget, $value);

        return ApiResponse::success(null, 'Budget value deleted successfully.');
    }
}
