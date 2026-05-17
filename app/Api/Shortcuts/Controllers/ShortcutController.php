<?php

namespace App\Api\Shortcuts\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Shortcuts\Models\Shortcut;
use App\Api\Shortcuts\Requests\StoreShortcutRequest;
use App\Api\Shortcuts\Requests\UpdateShortcutRequest;
use App\Api\Shortcuts\Resources\ShortcutResource;
use App\Api\Shortcuts\Services\ShortcutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShortcutController
{
    use HandlesApiAuth;

    public function __construct(private readonly ShortcutService $shortcutService) {}

    /**
     * @OA\Get(
     *     path="/api/shortcuts",
     *     operationId="shortcutIndex",
     *     tags={"Shortcuts"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Shortcuts retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Shortcut")),
     *             @OA\Property(property="message", type="string", example="Shortcuts retrieved successfully.")
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            ShortcutResource::collection($this->shortcutService->list($request->user())),
            'Shortcuts retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/shortcuts/{shortcut}",
     *     operationId="shortcutShow",
     *     tags={"Shortcuts"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="shortcut",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Shortcut retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Shortcut"),
     *             @OA\Property(property="message", type="string", example="Shortcut retrieved successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized"
     *     )
     * )
     */
    public function show(Request $request, Shortcut $shortcut): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $shortcut), 403);

        return ApiResponse::success(new ShortcutResource($shortcut), 'Shortcut retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/shortcuts",
     *     operationId="shortcutStore",
     *     tags={"Shortcuts"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"title", "url"},
     *
     *             @OA\Property(property="title", type="string", maxLength=255),
     *             @OA\Property(property="url", type="string", maxLength=255),
     *             @OA\Property(property="order", type="integer", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Shortcut created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Shortcut"),
     *             @OA\Property(property="message", type="string", example="Shortcut created successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function store(StoreShortcutRequest $request): JsonResponse
    {
        $shortcut = $this->shortcutService->create($request->user(), $request->validated());

        return ApiResponse::success(new ShortcutResource($shortcut), 'Shortcut created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/api/shortcuts/{shortcut}",
     *     operationId="shortcutUpdate",
     *     tags={"Shortcuts"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="shortcut",
     *         in="path",
     *         required=true,
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
     *             @OA\Property(property="url", type="string", maxLength=255),
     *             @OA\Property(property="order", type="integer", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Shortcut updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Shortcut"),
     *             @OA\Property(property="message", type="string", example="Shortcut updated successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function update(UpdateShortcutRequest $request, Shortcut $shortcut): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $shortcut), 403);

        $shortcut = $this->shortcutService->update($shortcut, $request->validated());

        return ApiResponse::success(new ShortcutResource($shortcut), 'Shortcut updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/api/shortcuts/{shortcut}",
     *     operationId="shortcutDestroy",
     *     tags={"Shortcuts"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="shortcut",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Shortcut deleted successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", nullable=true),
     *             @OA\Property(property="message", type="string", example="Shortcut deleted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized"
     *     )
     * )
     */
    public function reorder(Request $request): JsonResponse
    {
        $request->validate(['shortcuts' => 'required|array', 'shortcuts.*' => 'string']);

        $this->shortcutService->reorder($request->user(), $request->input('shortcuts'));

        return ApiResponse::success(null, 'Shortcuts reordered successfully.');
    }

    public function destroy(Request $request, Shortcut $shortcut): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $shortcut), 403);

        $this->shortcutService->destroy($shortcut);

        return ApiResponse::success(null, 'Shortcut deleted successfully.');
    }
}
