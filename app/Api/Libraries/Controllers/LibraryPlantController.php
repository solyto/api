<?php

namespace App\Api\Libraries\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Libraries\Models\LibraryPlant;
use App\Api\Libraries\Requests\Plants\StoreLibraryPlantRequest;
use App\Api\Libraries\Requests\Plants\UpdateLibraryPlantRequest;
use App\Api\Libraries\Resources\LibraryPlantResource;
use App\Api\Libraries\Services\LibraryPlantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibraryPlantController
{
    use HandlesApiAuth;

    public function __construct(private readonly LibraryPlantService $libraryPlantService) {}

    /**
     * @OA\Get(
     *     path="/api/libraries/plants",
     *     operationId="listLibraryPlants",
     *     tags={"Libraries - Plants"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Plants retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Plants retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LibraryPlant"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            LibraryPlantResource::collection($this->libraryPlantService->list($request->user())),
            'Plants retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/libraries/plants/{plant}",
     *     operationId="showLibraryPlant",
     *     tags={"Libraries - Plants"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="plant",
     *         in="path",
     *         required=true,
     *         description="Plant ID",
     *
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Plant retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Plant retrieved successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryPlant")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Plant not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, LibraryPlant $plant): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $plant), 403);

        $plant = $this->libraryPlantService->find($plant);

        return ApiResponse::success(new LibraryPlantResource($plant), 'Plant retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/plants",
     *     operationId="storeLibraryPlant",
     *     tags={"Libraries - Plants"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name"},
     *
     *             @OA\Property(property="name", type="string", maxLength=255),
     *             @OA\Property(property="latin_name", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="location", type="string", enum={"indoor", "outdoor", "both"}, nullable=true),
     *             @OA\Property(property="sunlight", type="string", enum={"full_sun", "partial_sun", "indirect", "shade"}, nullable=true),
     *             @OA\Property(property="current_size", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="max_size", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="acquired_at", type="string", format="date", nullable=true),
     *             @OA\Property(property="winter_hardy", type="boolean", nullable=true),
     *             @OA\Property(property="instructions", type="string", nullable=true),
     *             @OA\Property(property="cover_path", type="string", format="uri", maxLength=255, nullable=true),
     *             @OA\Property(property="link", type="string", format="uri", maxLength=255, nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Plant created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Plant created successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryPlant")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function store(StoreLibraryPlantRequest $request): JsonResponse
    {
        $plant = $this->libraryPlantService->create($request->user(), $request->validated());

        return ApiResponse::success(new LibraryPlantResource($plant), 'Plant created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/api/libraries/plants/{plant}",
     *     operationId="updateLibraryPlant",
     *     tags={"Libraries - Plants"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="plant",
     *         in="path",
     *         required=true,
     *         description="Plant ID",
     *
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", maxLength=255),
     *             @OA\Property(property="latin_name", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="location", type="string", enum={"indoor", "outdoor", "both"}, nullable=true),
     *             @OA\Property(property="sunlight", type="string", enum={"full_sun", "partial_sun", "indirect", "shade"}, nullable=true),
     *             @OA\Property(property="current_size", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="max_size", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="acquired_at", type="string", format="date", nullable=true),
     *             @OA\Property(property="winter_hardy", type="boolean", nullable=true),
     *             @OA\Property(property="instructions", type="string", nullable=true),
     *             @OA\Property(property="cover_path", type="string", format="uri", maxLength=255, nullable=true),
     *             @OA\Property(property="link", type="string", format="uri", maxLength=255, nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Plant updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Plant updated successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryPlant")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateLibraryPlantRequest $request, LibraryPlant $plant): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $plant), 403);

        $plant = $this->libraryPlantService->update($plant, $request->validated());

        return ApiResponse::success(new LibraryPlantResource($plant), 'Plant updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/api/libraries/plants/{plant}",
     *     operationId="destroyLibraryPlant",
     *     tags={"Libraries - Plants"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="plant",
     *         in="path",
     *         required=true,
     *         description="Plant ID",
     *
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Plant deleted successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Plant deleted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, LibraryPlant $plant): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $plant), 403);

        $this->libraryPlantService->destroy($plant);

        return ApiResponse::success(null, 'Plant deleted successfully.');
    }
}
