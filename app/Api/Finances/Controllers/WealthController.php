<?php

namespace App\Api\Finances\Controllers;

use App\Api\ApiResponse;
use App\Api\Finances\Models\WealthField;
use App\Api\Finances\Models\WealthValue;
use App\Api\Finances\Requests\StoreWealthFieldRequest;
use App\Api\Finances\Requests\StoreWealthValueRequest;
use App\Api\Finances\Requests\UpdateWealthFieldRequest;
use App\Api\Finances\Requests\UpdateWealthValueRequest;
use App\Api\Finances\Resources\WealthFieldResource;
use App\Api\Finances\Resources\WealthValueResource;
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
     *     path="/finances/wealth/fields",
     *     operationId="listWealthFields",
     *     tags={"Finances"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Wealth fields retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
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
     *     path="/finances/wealth/fields",
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
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
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
     *     path="/finances/wealth/fields/{field}",
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
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
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
     *     path="/finances/wealth/fields/{field}",
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
    public function destroyField(Request $request, WealthField $field): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $field), 403);

        $this->wealthService->destroyField($field);

        return ApiResponse::success(null, 'Wealth Field deleted successfully.');
    }

    /**
     * @OA\Put(
     *     path="/finances/wealth/fields/{field}/value",
     *     operationId="updateWealthFieldValue",
     *     tags={"Finances"},
     *     description="Adds a value to the field. A field has at most one value per day: if it already has a value on the given date, that value is replaced.",
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
     *             @OA\Property(property="value", type="number"),
     *             @OA\Property(property="date", type="string", format="date", description="Date of the value (Y-m-d), defaults to today, must not be in the future")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Wealth Field updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/WealthValue")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function storeValue(StoreWealthValueRequest $request, WealthField $field): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $field), 403);

        $value = $this->wealthService->storeValue($field, $request->validated());

        return ApiResponse::success(new WealthValueResource($value), 'Wealth Field updated successfully.');
    }

    /**
     * @OA\Put(
     *     path="/finances/wealth/fields/{field}/values/{value}",
     *     operationId="updateWealthValue",
     *     tags={"Finances"},
     *     description="Corrects the amount and/or the date of a single value. Moving a value to a date that already has a value of this field returns 422.",
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
     *     @OA\Parameter(
     *         name="value",
     *         in="path",
     *         required=true,
     *         description="Wealth Value ID",
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
     *         description="Wealth value updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/WealthValue")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Wealth field or value not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function updateValue(UpdateWealthValueRequest $request, WealthField $field, WealthValue $value): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $field), 403);
        abort_unless($value->field()->is($field), 403);

        $value = $this->wealthService->updateValue($field, $value, $request->validated());

        return ApiResponse::success(new WealthValueResource($value), 'Wealth value updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/finances/wealth/fields/{field}/values/{value}",
     *     operationId="destroyWealthValue",
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
     *     @OA\Parameter(
     *         name="value",
     *         in="path",
     *         required=true,
     *         description="Wealth Value ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Wealth value deleted successfully",
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
     *     @OA\Response(response=404, description="Wealth field or value not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroyValue(Request $request, WealthField $field, WealthValue $value): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $field), 403);
        abort_unless($value->field()->is($field), 403);

        $this->wealthService->destroyValue($field, $value);

        return ApiResponse::success(null, 'Wealth value deleted successfully.');
    }
}
