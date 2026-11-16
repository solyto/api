<?php

namespace App\Api\Clipboard\Controllers;

use App\Api\ApiResponse;
use App\Api\Clipboard\Models\Clipboard;
use App\Api\Clipboard\Requests\StoreClipboardFileRequest;
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
     *     path="/clipboard",
     *     operationId="clipboardList",
     *     tags={"Clipboard"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="include_files",
     *         in="query",
     *         required=false,
     *         description="Include entries of type file; older clients do not know the type.",
     *
     *         @OA\Schema(type="boolean")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Clipboards retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Clipboard"))
     *         )
     *     )
     * )
     */
    public function list(Request $request): JsonResponse
    {
        return ApiResponse::success(
            ClipboardResource::collection($this->clipboardService->list($request->user(), $request->boolean('include_files'))),
            'Clipboards retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/clipboard",
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
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Clipboard")
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
     *     path="/clipboard/image",
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
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Clipboard")
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
     *     path="/clipboard/{clipboard}/image",
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

        $path = $this->clipboardService->getImagePath($request->user()->id, $clipboard);

        if (! $path) {
            return ApiResponse::error('No image found.', 404);
        }

        return response()->file($path, [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /**
     * @OA\Post(
     *     path="/clipboard/file",
     *     operationId="clipboardStoreFile",
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
     *                 required={"file"},
     *
     *                 @OA\Property(property="file", type="string", format="binary", description="pdf, txt, md, csv, doc, docx, ppt, pptx, xls or xlsx")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Clipboard file saved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Clipboard")
     *         )
     *     ),
     *
     *     @OA\Response(response=413, description="File too large", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse")),
     *     @OA\Response(response=429, description="Daily file limit reached", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function storeFile(StoreClipboardFileRequest $request): JsonResponse
    {
        return ApiResponse::success(
            new ClipboardResource($this->clipboardService->storeFile($request->user(), $request->file('file'))),
            'Clipboard file saved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/clipboard/{clipboard}/file",
     *     operationId="clipboardGetFile",
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
     *     @OA\Response(response=200, description="File returned as attachment"),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=404, description="No file found")
     * )
     */
    public function getFile(Request $request, Clipboard $clipboard): BinaryFileResponse|JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $clipboard), 403);

        $path = $this->clipboardService->getFilePath($clipboard);

        if (! $path) {
            return ApiResponse::error('No file found.', 404);
        }

        return response()->download($path, $clipboard->file_name, [
            'Content-Type' => $clipboard->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/clipboard/{clipboard}",
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
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
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
