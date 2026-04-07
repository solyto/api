<?php

namespace App\Api\Libraries\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Libraries\Enums\LibraryRecommendationEnum;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Libraries\Requests\Music\StoreLibraryMusicRequest;
use App\Api\Libraries\Requests\Music\UpdateLibraryMusicRequest;
use App\Api\Libraries\Resources\DeezerAlbumImportResource;
use App\Api\Libraries\Resources\DiscogsAlbumImportResource;
use App\Api\Libraries\Resources\LibraryMusicReleaseResource;
use App\Api\Libraries\Resources\LibraryMusicResource;
use App\Api\Libraries\Resources\LibraryRecommendationResource;
use App\Api\Libraries\Services\LibraryMusicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibraryMusicController
{
    use HandlesApiAuth;

    public function __construct(private readonly LibraryMusicService $libraryMusicService) {}

    /**
     * @OA\Get(
     *     path="/api/libraries/music",
     *     operationId="listLibraryMusic",
     *     tags={"Libraries - Music"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Music list retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Music list retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LibraryMusic"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            LibraryMusicResource::collection($this->libraryMusicService->list($request->user())),
            'Music list retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/libraries/music/{music}",
     *     operationId="showLibraryMusic",
     *     tags={"Libraries - Music"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="music",
     *         in="path",
     *         required=true,
     *         description="Music ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Music entry retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Music entry retrieved successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryMusic")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Music not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, LibraryMusic $music): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $music), 403);

        $music = $this->libraryMusicService->find($music);

        return ApiResponse::success(new LibraryMusicResource($music), 'Music entry retrieved successfully.');
    }

    /**
     * @OA\Get(
     *     path="/api/libraries/music/recommend/{type}",
     *     operationId="recommendLibraryMusic",
     *     tags={"Libraries - Music"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="type",
     *         in="path",
     *         required=true,
     *         description="Recommendation type",
     *
     *         @OA\Schema(type="string", enum={"random", "favorite", "new"})
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Recommendation retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Recommendation retrieved successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryRecommendation")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="No recommendation found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function recommend(Request $request, LibraryRecommendationEnum $type): JsonResponse
    {
        if ($type === LibraryRecommendationEnum::NEW) {
            $recommendation = $this->libraryMusicService->recommendNew($request->user());

            if (! $recommendation) {
                return ApiResponse::error('No recommendation found.', 404);
            }

            return ApiResponse::success(
                new LibraryRecommendationResource($recommendation),
                'Recommendation retrieved successfully.'
            );
        }

        $recommendation = $this->libraryMusicService->recommend($request->user(), $type);

        if (! $recommendation) {
            return ApiResponse::error('No recommendation found.', 404);
        }

        return ApiResponse::success(
            new LibraryRecommendationResource($recommendation),
            'Recommendation retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/libraries/music/releases",
     *     operationId="listLibraryMusicReleases",
     *     tags={"Libraries - Music"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Releases retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Releases retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LibraryMusicRelease"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function releases(Request $request): JsonResponse
    {
        $releases = $this->libraryMusicService->releases($request->user());

        return ApiResponse::success(
            LibraryMusicReleaseResource::collection($releases),
            'Releases retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/music",
     *     operationId="storeLibraryMusic",
     *     tags={"Libraries - Music"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"title", "artist"},
     *
     *             @OA\Property(property="title", type="string", maxLength=255),
     *             @OA\Property(property="artist", type="string", maxLength=255),
     *             @OA\Property(property="type", type="string", maxLength=50, nullable=true),
     *             @OA\Property(property="format", type="string", maxLength=50, nullable=true),
     *             @OA\Property(property="condition", type="string", maxLength=50, nullable=true),
     *             @OA\Property(property="rating", type="integer", minimum=1, maximum=5, nullable=true),
     *             @OA\Property(property="publication_year", type="integer", nullable=true),
     *             @OA\Property(property="acquired_where", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="additional_info", type="string", nullable=true),
     *             @OA\Property(property="cover_path", type="string", format="uri", maxLength=255, nullable=true),
     *             @OA\Property(property="wishlist", type="boolean", nullable=true),
     *             @OA\Property(property="link", type="string", format="uri", maxLength=255, nullable=true),
     *             @OA\Property(property="genres", type="array", items={}, nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Music entry created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Music entry created successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryMusic")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function store(StoreLibraryMusicRequest $request): JsonResponse
    {
        $music = $this->libraryMusicService->create($request->user(), $request->validated());

        return ApiResponse::success(new LibraryMusicResource($music), 'Music entry created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/api/libraries/music/{music}",
     *     operationId="updateLibraryMusic",
     *     tags={"Libraries - Music"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="music",
     *         in="path",
     *         required=true,
     *         description="Music ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"title", "artist"},
     *
     *             @OA\Property(property="title", type="string", maxLength=255),
     *             @OA\Property(property="artist", type="string", maxLength=255),
     *             @OA\Property(property="type", type="string", maxLength=50, nullable=true),
     *             @OA\Property(property="format", type="string", maxLength=50, nullable=true),
     *             @OA\Property(property="condition", type="string", maxLength=50, nullable=true),
     *             @OA\Property(property="rating", type="integer", minimum=1, maximum=5, nullable=true),
     *             @OA\Property(property="publication_year", type="integer", nullable=true),
     *             @OA\Property(property="acquired_where", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="additional_info", type="string", nullable=true),
     *             @OA\Property(property="cover_path", type="string", format="uri", maxLength=255, nullable=true),
     *             @OA\Property(property="wishlist", type="boolean", nullable=true),
     *             @OA\Property(property="link", type="string", format="uri", maxLength=255, nullable=true),
     *             @OA\Property(property="genres", type="array", items={}, nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Music entry updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Music entry updated successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryMusic")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateLibraryMusicRequest $request, LibraryMusic $music): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $music), 403);

        $music = $this->libraryMusicService->update($music, $request->validated());

        return ApiResponse::success(new LibraryMusicResource($music), 'Music entry updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/api/libraries/music/{music}",
     *     operationId="destroyLibraryMusic",
     *     tags={"Libraries - Music"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="music",
     *         in="path",
     *         required=true,
     *         description="Music ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Music entry deleted successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Music entry deleted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, LibraryMusic $music): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $music), 403);

        $this->libraryMusicService->destroy($music);

        return ApiResponse::success(null, 'Music entry deleted successfully.');
    }

    /**
     * @OA\Get(
     *     path="/api/libraries/music/search/deezer/{artist}/{album}",
     *     operationId="searchAlbumOnDeezer",
     *     tags={"Libraries - Music"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="artist",
     *         in="path",
     *         required=true,
     *         description="Artist name",
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\Parameter(
     *         name="album",
     *         in="path",
     *         required=true,
     *         description="Album name",
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Info from Deezer retrieved",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Info from Deezer retrieved."),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function searchAlbumOnDeezer(Request $request, string $artist, string $album): JsonResponse
    {
        $search = $this->libraryMusicService->searchOnDeezer($artist, $album);

        return ApiResponse::success($search, 'Info from Deeezer retrieved.');
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/music/import/deezer",
     *     operationId="importAlbumFromDeezer",
     *     tags={"Libraries - Music"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"url"},
     *
     *             @OA\Property(property="url", type="string", format="uri", example="https://www.deezer.com/en/album/example")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Album imported successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Album imported successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/DeezerAlbumImport")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Album not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function importAlbumFromDeezer(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => 'required|string|url']);

        $album = $this->libraryMusicService->importFromDeezer($data['url']);

        if (! $album) {
            return ApiResponse::error('Album not found.', 404);
        }

        return ApiResponse::success(new DeezerAlbumImportResource($album), 'Album imported successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/music/import/discogs",
     *     operationId="importAlbumFromDiscogs",
     *     tags={"Libraries - Music"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"url"},
     *
     *             @OA\Property(property="url", type="string", format="uri", example="https://www.discogs.com/master/example")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Album imported successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Album imported successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/DiscogsAlbumImport")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Album not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function importAlbumFromDiscogs(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => 'required|string|url']);

        $album = $this->libraryMusicService->importFromDiscogs($data['url']);

        if (! $album) {
            return ApiResponse::error('Album not found.', 404);
        }

        return ApiResponse::success(new DiscogsAlbumImportResource($album), 'Album imported successfully.');
    }
}
