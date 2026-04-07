<?php

namespace App\Api\Finances\Controllers;

use App\Api\ApiResponse;
use App\Api\Finances\Models\WealthField;
use App\Api\Finances\Requests\StoreWealthFieldRequest;
use App\Api\Finances\Requests\StoreWealthValueRequest;
use App\Api\Finances\Requests\UpdateWealthFieldRequest;
use App\Api\Finances\Resources\WealthFieldResource;
use App\Api\Finances\Services\WealthService;
use App\Api\HandlesApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WealthController
{
    use HandlesApiAuth;

    public function __construct(private readonly WealthService $wealthService) {}

    /**
     * @OA\Get(
     *     path="/api/finances/wealth/fields",
     *     operationId="listWealthFields",
     *     tags={"Finances"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Wealth fields retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Wealth fields retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/WealthField"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function listFields(Request $request): JsonResponse
    {
        return ApiResponse::success(
            WealthFieldResource::collection($this->wealthService->listFields($request->user())),
            'Wealth fields retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/api/finances/wealth/fields",
     *     operationId="storeWealthField",
     *     tags={"Finances"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"title"},
     *
     *             @OA\Property(property="title", type="string", maxLength=255)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Wealth field created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Wealth field created successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/WealthField")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function storeField(StoreWealthFieldRequest $request): JsonResponse
    {
        $field = $this->wealthService->createField($request->user(), $request->validated());

        return ApiResponse::success(
            new WealthFieldResource($field),
            'Wealth field created successfully.',
            201
        );
    }

    /**
     * @OA\Put(
     *     path="/api/finances/wealth/fields/{field}",
     *     operationId="updateWealthField",
     *     tags={"Finances"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="field",
     *         in="path",
     *         required=true,
     *         description="Wealth Field ID",
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
     *             @OA\Property(property="title", type="string", maxLength=255)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Wealth Field updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Wealth Field updated successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/WealthField")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function updateField(UpdateWealthFieldRequest $request, WealthField $field): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $field), 403);

        $field = $this->wealthService->updateField($field, $request->validated());

        return ApiResponse::success(
            new WealthFieldResource($field),
            'Wealth Field updated successfully.'
        );
    }

    /**
     * @OA\Delete(
     *     path="/api/finances/wealth/fields/{field}",
     *     operationId="destroyWealthField",
     *     tags={"Finances"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="field",
     *         in="path",
     *         required=true,
     *         description="Wealth Field ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Wealth Field deleted successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Wealth Field deleted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroyField(Request $request, WealthField $field): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $field), 403);

        $this->wealthService->destroyField($field);

        return ApiResponse::success(null, 'Wealth Field deleted successfully.');
    }

    /**
     * @OA\Put(
     *     path="/api/finances/wealth/fields/{field}/value",
     *     operationId="updateWealthFieldValue",
     *     tags={"Finances"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="field",
     *         in="path",
     *         required=true,
     *         description="Wealth Field ID",
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
     *             @OA\Property(property="value", type="number")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Wealth Field updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Wealth Field updated successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function updateValue(StoreWealthValueRequest $request, WealthField $field): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $field), 403);

        $this->wealthService->updateValue($field, $request->validated());

        return ApiResponse::success([], 'Wealth Field updated successfully.');
    }
}
