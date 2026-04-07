<?php

namespace App\Api\Libraries\Controllers;

use App\Api\HandlesApiAuth;
use App\Api\Libraries\Enums\LibraryTypeEnum;
use App\Api\Libraries\Services\LibraryCoverService;
use Illuminate\Http\Request;

class LibraryCoverController
{
    use HandlesApiAuth;

    /**
     * @OA\Get(
     *     path="/api/libraries/covers/{type}/{fileName}",
     *     operationId="showLibraryCover",
     *     tags={"Libraries - Covers"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="type",
     *         in="path",
     *         required=true,
     *         description="Library type (books, music, movies, games)",
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\Parameter(
     *         name="fileName",
     *         in="path",
     *         required=true,
     *         description="Cover file name",
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Cover image",
     *
     *         @OA\MediaType(mediaType="image/jpeg")
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Cover not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, LibraryTypeEnum $type, string $fileName)
    {
        $service = new LibraryCoverService;
        $cover = $service->loadCover($request->user()->id, $type, $fileName);

        if (! $cover) {
            abort(404);
        }

        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $mimeType = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg'
        };

        sleep(1);

        return response($cover)->header('Content-Type', $mimeType);
    }
}
