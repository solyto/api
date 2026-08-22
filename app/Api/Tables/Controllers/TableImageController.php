<?php

namespace App\Api\Tables\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Tables\Models\Table;
use App\Api\Tables\Services\TableImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TableImageController
{
    use HandlesApiAuth;

    public function __construct(private readonly TableImageService $imageService) {}

    /**
     * @OA\Post(
     *     path="/v1/tables/{table}/images",
     *     operationId="tableImageUpload",
     *     summary="Upload an image to use as a picture cell value",
     *     tags={"Tables"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="table", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Image uploaded successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="object", @OA\Property(property="file_name", type="string"))
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function store(Request $request, Table $table): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $table), 403);

        $request->validate([
            'image' => 'required|image|max:10240',
        ]);

        $fileName = $this->imageService->upload($request->user()->id, $table, $request->file('image'));

        abort_unless($fileName !== false, 422, 'Unable to upload image.');

        return ApiResponse::success(['file_name' => $fileName], 'Image uploaded successfully.', 201);
    }

    /**
     * @OA\Get(
     *     path="/v1/tables/{table}/images/{fileName}",
     *     operationId="tableImageShow",
     *     summary="Get a table's picture cell image",
     *     tags={"Tables"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="table", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="fileName", in="path", required=true, @OA\Schema(type="string")),
     *
     *     @OA\Response(response=200, description="Image", @OA\MediaType(mediaType="image/jpeg")),
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, Table $table, string $fileName)
    {
        abort_unless($this->isResourceOwner($request, $table), 403);

        $image = $this->imageService->load($request->user()->id, $table, $fileName);

        abort_unless($image !== false, 404);

        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $mimeType = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        return response($image)->header('Content-Type', $mimeType);
    }
}
