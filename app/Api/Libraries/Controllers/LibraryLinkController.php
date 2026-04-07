<?php

namespace App\Api\Libraries\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Libraries\Models\LibraryLink;
use App\Api\Libraries\Requests\Links\StoreLibraryLinkRequest;
use App\Api\Libraries\Requests\Links\UpdateLibraryLinkRequest;
use App\Api\Libraries\Resources\LibraryLinkResource;
use App\Api\Libraries\Services\LibraryLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibraryLinkController
{
    use HandlesApiAuth;

    public function __construct(private readonly LibraryLinkService $libraryLinkService) {}

    /**
     * @OA\Get(
     *     path="/api/libraries/links",
     *     operationId="listLibraryLinks",
     *     tags={"Libraries - Links"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Links retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Links retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LibraryLink"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            LibraryLinkResource::collection($this->libraryLinkService->list($request->user())),
            'Links retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/libraries/links/{link}",
     *     operationId="showLibraryLink",
     *     tags={"Libraries - Links"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="link",
     *         in="path",
     *         required=true,
     *         description="Link ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Link retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Link retrieved successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryLink")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Link not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, LibraryLink $link): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $link), 403);

        $link = $this->libraryLinkService->find($link);

        return ApiResponse::success(new LibraryLinkResource($link), 'Link retrieved successfully.');
    }

    /**
     * @OA\Get(
     *     path="/api/libraries/links/newest",
     *     operationId="newestLibraryLinks",
     *     tags={"Libraries - Links"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Links retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Links retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LibraryLink"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function newest(Request $request): JsonResponse
    {
        return ApiResponse::success(
            LibraryLinkResource::collection($this->libraryLinkService->newest($request->user())),
            'Links retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/links",
     *     operationId="storeLibraryLink",
     *     tags={"Libraries - Links"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"title", "url"},
     *
     *             @OA\Property(property="title", type="string", maxLength=255),
     *             @OA\Property(property="url", type="string", format="uri", maxLength=255),
     *             @OA\Property(property="is_favorite", type="boolean"),
     *             @OA\Property(property="cover_path", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="tags", type="array", items={}, nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Link created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Link created successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryLink")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function store(StoreLibraryLinkRequest $request): JsonResponse
    {
        $link = $this->libraryLinkService->create($request->user(), $request->validated());

        return ApiResponse::success(new LibraryLinkResource($link), 'Link created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/api/libraries/links/{link}",
     *     operationId="updateLibraryLink",
     *     tags={"Libraries - Links"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="link",
     *         in="path",
     *         required=true,
     *         description="Link ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"title", "url"},
     *
     *             @OA\Property(property="title", type="string", maxLength=255),
     *             @OA\Property(property="url", type="string", format="uri", maxLength=255),
     *             @OA\Property(property="is_favorite", type="boolean"),
     *             @OA\Property(property="cover_path", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="tags", type="array", items={}, nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Link updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Link updated successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryLink")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateLibraryLinkRequest $request, LibraryLink $link): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $link), 403);

        $link = $this->libraryLinkService->update($link, $request->validated());

        return ApiResponse::success(new LibraryLinkResource($link), 'Link updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/api/libraries/links/{link}",
     *     operationId="destroyLibraryLink",
     *     tags={"Libraries - Links"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="link",
     *         in="path",
     *         required=true,
     *         description="Link ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Link deleted successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Link deleted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, LibraryLink $link): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $link), 403);

        $this->libraryLinkService->destroy($link);

        return ApiResponse::success(null, 'Link deleted successfully.');
    }
}
