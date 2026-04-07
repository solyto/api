<?php

namespace App\Api\Clipboard\Controllers;

use App\Api\ApiResponse;
use App\Api\Clipboard\Models\Clipboard;
use App\Api\Clipboard\Requests\StoreClipboardImageRequest;
use App\Api\Clipboard\Requests\StoreClipboardRequest;
use App\Api\Clipboard\Resources\ClipboardResource;
use App\Api\Clipboard\Services\ClipboardService;
use App\Api\HandlesApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ClipboardController
{
    use HandlesApiAuth;

    public function __construct(private readonly ClipboardService $clipboardService) {}

    /**
     * @OA\Get(
     *     path="/api/clipboards",
     *     operationId="clipboardList",
     *     tags={"Clipboard"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Clipboards retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Clipboard")),
     *             @OA\Property(property="message", type="string", example="Clipboards retrieved successfully.")
     *         )
     *     )
     * )
     */
    public function list(Request $request): JsonResponse
    {
        return ApiResponse::success(
            ClipboardResource::collection($this->clipboardService->list($request->user())),
            'Clipboards retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/api/clipboards",
     *     operationId="clipboardStore",
     *     tags={"Clipboard"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="content", type="string", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Clipboard created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Clipboard"),
     *             @OA\Property(property="message", type="string", example="Clipboard created successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function store(StoreClipboardRequest $request): JsonResponse
    {
        $clipboard = $this->clipboardService->store($request->user(), $request->validated());

        return ApiResponse::success(
            new ClipboardResource($clipboard),
            'Clipboard created successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/api/clipboards/image",
     *     operationId="clipboardStoreImage",
     *     tags={"Clipboard"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *
     *             @OA\Schema(
     *                 required={"image"},
     *
     *                 @OA\Property(property="image", type="string", format="binary")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Clipboard image saved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Clipboard"),
     *             @OA\Property(property="message", type="string", example="Clipboard image saved successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Failed to save clipboard image"
     *     )
     * )
     */
    public function storeImage(StoreClipboardImageRequest $request): JsonResponse
    {
        $clipboard = $this->clipboardService->storeImage($request->user(), $request->file('image'));

        if (! $clipboard) {
            return ApiResponse::error('Failed to save clipboard image.', 422);
        }

        return ApiResponse::success(
            new ClipboardResource($clipboard),
            'Clipboard image saved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/clipboards/{clipboard}/image",
     *     operationId="clipboardGetImage",
     *     tags={"Clipboard"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="clipboard",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Image file returned"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No image found"
     *     )
     * )
     */
    public function getImage(Request $request, Clipboard $clipboard): BinaryFileResponse|JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $clipboard), 403);

        if ($clipboard->type !== 'image' || ! $clipboard->file_path) {
            return ApiResponse::error('No image found.', 404);
        }

        $path = $this->clipboardService->getImagePath($request->user()->id, $clipboard);

        return response()->file($path);
    }

    /**
     * @OA\Delete(
     *     path="/api/clipboards/{clipboard}",
     *     operationId="clipboardDestroy",
     *     tags={"Clipboard"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="clipboard",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Clipboard entry deleted successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", nullable=true),
     *             @OA\Property(property="message", type="string", example="Clipboard entry deleted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized"
     *     )
     * )
     */
    public function destroy(Request $request, Clipboard $clipboard): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $clipboard), 403);

        $this->clipboardService->destroy($request->user(), $clipboard);

        return ApiResponse::success(null, 'Clipboard entry deleted successfully.');
    }
}
