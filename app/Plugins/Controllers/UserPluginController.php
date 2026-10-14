<?php

namespace App\Plugins\Controllers;

use App\Api\ApiResponse;
use App\Plugins\Ai\Resources\AiProviderResource;
use App\Plugins\Enums\PluginEnum;
use App\Plugins\Requests\UpdateUserPluginRequest;
use App\Plugins\Resources\UserPluginResource;
use App\Plugins\Services\UserPluginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserPluginController
{
    public function __construct(private readonly UserPluginService $userPluginService) {}

    /**
     * @OA\Get(
     *     path="/users/me/plugins",
     *     operationId="userPluginIndex",
     *     summary="List all plugins with their state for the current user",
     *     tags={"Plugins"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Plugins retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/UserPlugin"))
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            UserPluginResource::collection($this->userPluginService->list($request->user())),
            'Plugins retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/users/me/plugins/{plugin}",
     *     operationId="userPluginShow",
     *     summary="Get a plugin's state for the current user",
     *     tags={"Plugins"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="plugin",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="string", enum={"ai"})
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Plugin retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/UserPlugin")
     *         )
     *     ),
     *
     *     @OA\Response(response=404, description="Unknown plugin", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, PluginEnum $plugin): JsonResponse
    {
        return ApiResponse::success(
            new UserPluginResource($this->userPluginService->find($request->user(), $plugin)),
            'Plugin retrieved successfully.'
        );
    }

    /**
     * @OA\Put(
     *     path="/users/me/plugins/{plugin}",
     *     operationId="userPluginUpdate",
     *     summary="Create or update a plugin's configuration for the current user",
     *     tags={"Plugins"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="plugin",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="string", enum={"ai"})
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"enabled","provider","model"},
     *
     *             @OA\Property(property="enabled", type="boolean"),
     *             @OA\Property(property="api_key", type="string", writeOnly=true, description="Required when no key is stored yet. Leave it out to keep the stored key."),
     *             @OA\Property(property="provider", type="string", enum={"openai","openrouter","mistral"}),
     *             @OA\Property(property="model", type="string", description="A model id from GET /plugins/ai/providers for the given provider")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Plugin updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/UserPlugin")
     *         )
     *     ),
     *
     *     @OA\Response(response=404, description="Unknown plugin", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateUserPluginRequest $request, PluginEnum $plugin): JsonResponse
    {
        $state = $this->userPluginService->update($request->user(), $plugin, $request->validated());

        return ApiResponse::success(new UserPluginResource($state), 'Plugin updated successfully.');
    }

    /**
     * @OA\Get(
     *     path="/plugins/ai/providers",
     *     operationId="aiProviderIndex",
     *     summary="List AI providers and their models",
     *     tags={"Plugins"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="AI providers retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/AiProvider"))
     *         )
     *     )
     * )
     */
    public function providers(): JsonResponse
    {
        return ApiResponse::success(
            AiProviderResource::collection($this->userPluginService->aiProviders()),
            'AI providers retrieved successfully.'
        );
    }
}
