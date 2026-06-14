<?php

namespace App\Api\Libraries\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Libraries\Enums\RecipeServiceEnum;
use App\Api\Libraries\Models\LibraryRecipe;
use App\Api\Libraries\Requests\Recipes\StoreLibraryRecipeRequest;
use App\Api\Libraries\Requests\Recipes\UpdateLibraryRecipeRequest;
use App\Api\Libraries\Resources\LibraryRecipeResource;
use App\Api\Libraries\Resources\RecipeReleaseResource;
use App\Api\Libraries\Services\LibraryRecipeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibraryRecipeController
{
    use HandlesApiAuth;

    public function __construct(private readonly LibraryRecipeService $libraryRecipeService) {}

    /**
     * @OA\Get(
     *     path="/api/libraries/recipes",
     *     operationId="listLibraryRecipes",
     *     tags={"Libraries - Recipes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Recipes retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Recipes retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LibraryRecipe"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            LibraryRecipeResource::collection($this->libraryRecipeService->list($request->user())),
            'Recipes retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/libraries/recipes/{recipe}",
     *     operationId="showLibraryRecipe",
     *     tags={"Libraries - Recipes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="recipe",
     *         in="path",
     *         required=true,
     *         description="Recipe ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Recipe retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Recipe retrieved successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryRecipe")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Recipe not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, LibraryRecipe $recipe): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $recipe), 403);

        return ApiResponse::success(new LibraryRecipeResource($recipe), 'Recipe retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/recipes",
     *     operationId="storeLibraryRecipe",
     *     tags={"Libraries - Recipes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"title", "platform"},
     *
     *             @OA\Property(property="title", type="string", maxLength=255),
     *             @OA\Property(property="rating", type="integer", minimum=1, maximum=5, nullable=true),
     *             @OA\Property(property="time_to_make", type="integer", nullable=true),
     *             @OA\Property(property="cover_path", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="link", type="string", format="uri", maxLength=255, nullable=true),
     *             @OA\Property(property="description", type="string", nullable=true),
     *             @OA\Property(property="ingredients", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="type", type="string", enum={"breakfast", "lunch", "dinner", "snack", "dessert", "drink", "other"}, nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Recipe created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Recipe created successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryRecipe")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function store(StoreLibraryRecipeRequest $request): JsonResponse
    {
        $recipe = $this->libraryRecipeService->create($request->user(), $request->validated());

        return ApiResponse::success(new LibraryRecipeResource($recipe), 'Recipe created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/api/libraries/recipes/{recipe}",
     *     operationId="updateLibraryRecipe",
     *     tags={"Libraries - Recipes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="recipe",
     *         in="path",
     *         required=true,
     *         description="Recipe ID",
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
     *             @OA\Property(property="rating", type="integer", minimum=1, maximum=5, nullable=true),
     *             @OA\Property(property="time_to_make", type="integer", nullable=true),
     *             @OA\Property(property="cover_path", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="link", type="string", format="uri", maxLength=255, nullable=true),
     *             @OA\Property(property="description", type="string", nullable=true),
     *             @OA\Property(property="ingredients", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="type", type="string", enum={"breakfast", "lunch", "dinner", "snack", "dessert", "drink", "other"}, nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Recipe updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Recipe updated successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryRecipe")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateLibraryRecipeRequest $request, LibraryRecipe $recipe): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $recipe), 403);

        $recipe = $this->libraryRecipeService->update($recipe, $request->validated());

        return ApiResponse::success(new LibraryRecipeResource($recipe), 'Recipe updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/api/libraries/recipes/{recipe}",
     *     operationId="destroyLibraryRecipe",
     *     tags={"Libraries - Recipes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="recipe",
     *         in="path",
     *         required=true,
     *         description="Recipe ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Recipe deleted successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Recipe deleted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, LibraryRecipe $recipe): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $recipe), 403);

        $this->libraryRecipeService->destroy($recipe);

        return ApiResponse::success(null, 'Recipe deleted successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/recipes/import/{service}",
     *     operationId="import",
     *     tags={"Libraries - Recipes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="service",
     *         in="path",
     *         required=true,
     *         description="Import service (chefkoch)",
     *
     *         @OA\Schema(type="string", enum={"chefkoch"})
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"url"},
     *
     *             @OA\Property(property="url", type="string", format="uri", example="https://www.chefkoch.de/rezepte/1632851270878963/Example.html")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Recipe imported successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Recipe imported successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/RecipeReleaseImport")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Recipe not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function import(Request $request, RecipeServiceEnum $service): JsonResponse
    {
        $data = $request->validate(['url' => 'required|string|url']);

        $recipe = $this->libraryRecipeService->import($service, $data['url']);

        if (!$recipe) {
            return ApiResponse::error('Recipe not found.', 404);
        }

        return ApiResponse::success(new RecipeReleaseResource($recipe), 'Recipe imported successfully.');
    }
}
