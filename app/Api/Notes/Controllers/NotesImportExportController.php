<?php

namespace App\Api\Notes\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Notes\Services\NoteImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotesImportExportController
{
    use HandlesApiAuth;

    public function __construct(
        private readonly NoteImportService $noteImportService,
    ) {}

    /**
     * @OA\Post(
     *     path="/v1/notes/import",
     *     operationId="noteImport",
     *     summary="Import notes from a file",
     *     tags={"Notes"},
     *     security={{"sanctum": {}}},
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
     *                 @OA\Property(
     *                     property="file",
     *                     type="string",
     *                     format="binary",
     *                     description="Markdown (.md) or ZIP (.zip) file"
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Notes imported successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Note"))
     *         )
     *     ),
     *
     *     @OA\Response(response=400, description="Invalid file", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function import(Request $request): JsonResponse
    {
        if (! $request->hasFile('file')) {
            return ApiResponse::error();
        }

        $file = $request->file('file');

        if (! $file->isValid()) {
            return ApiResponse::error();
        }

        if ($file->getClientOriginalExtension() !== 'md' && $file->getClientOriginalExtension() !== 'zip') {
            return ApiResponse::error();
        }

        $this->noteImportService->importFile($file, $request->user()->id);

        return ApiResponse::success(
            [],
            'Notes imported successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/v1/notes/export",
     *     operationId="noteExport",
     *     summary="Export notes to a file",
     *     tags={"Notes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="File exported successfully",
     *
     *         @OA\MediaType(
     *             mediaType="application/octet-stream",
     *
     *             @OA\Schema(type="string", format="binary")
     *         )
     *     )
     * )
     */
    public function export(): JsonResponse {}
}
