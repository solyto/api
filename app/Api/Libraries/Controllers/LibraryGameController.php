<?php

namespace App\Api\Libraries\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Libraries\Models\LibraryGame;
use App\Api\Libraries\Requests\Games\StoreLibraryGameRequest;
use App\Api\Libraries\Requests\Games\UpdateLibraryGameRequest;
use App\Api\Libraries\Resources\BggGameImportResource;
use App\Api\Libraries\Resources\LibraryGameResource;
use App\Api\Libraries\Resources\SteamGameImportResource;
use App\Api\Libraries\Services\LibraryGameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibraryGameController
{
    use HandlesApiAuth;

    public function __construct(private readonly LibraryGameService $libraryGameService) {}

    /**
     * @OA\Get(
     *     path="/api/libraries/games",
     *     operationId="listLibraryGames",
     *     tags={"Libraries - Games"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Games retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Games retrieved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LibraryGame"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            LibraryGameResource::collection($this->libraryGameService->list($request->user())),
            'Games retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/libraries/games/{game}",
     *     operationId="showLibraryGame",
     *     tags={"Libraries - Games"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="game",
     *         in="path",
     *         required=true,
     *         description="Game ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Game retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Game retrieved successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryGame")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Game not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, LibraryGame $game): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $game), 403);

        $game = $this->libraryGameService->find($game);

        return ApiResponse::success(new LibraryGameResource($game), 'Game retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/games",
     *     operationId="storeLibraryGame",
     *     tags={"Libraries - Games"},
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
     *             @OA\Property(property="cover_path", type="string", format="uri", maxLength=255, nullable=true),
     *             @OA\Property(property="link", type="string", format="uri", maxLength=255, nullable=true),
     *             @OA\Property(property="started_at", type="string", format="date", nullable=true),
     *             @OA\Property(property="finished_at", type="string", format="date", nullable=true),
     *             @OA\Property(property="publication_year", type="integer", nullable=true),
     *             @OA\Property(property="platform", type="string", enum={"pc", "playstation", "xbox", "nintendo", "mobile", "boardgame", "other"}),
     *             @OA\Property(property="developer", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="publisher", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="playtime_hours", type="integer", minimum=0, nullable=true),
     *             @OA\Property(property="completed", type="boolean", nullable=true),
     *             @OA\Property(property="wishlist", type="boolean", nullable=true),
     *             @OA\Property(property="genres", type="array", @OA\Items(type="integer"), nullable=true),
     *             @OA\Property(property="tags", type="array", @OA\Items(type="string"), nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Game created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Game created successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryGame")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function store(StoreLibraryGameRequest $request): JsonResponse
    {
        $game = $this->libraryGameService->create($request->user(), $request->validated());

        return ApiResponse::success(new LibraryGameResource($game), 'Game created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/api/libraries/games/{game}",
     *     operationId="updateLibraryGame",
     *     tags={"Libraries - Games"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="game",
     *         in="path",
     *         required=true,
     *         description="Game ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             @OA\Property(property="title", type="string", maxLength=255),
     *             @OA\Property(property="rating", type="integer", minimum=1, maximum=5, nullable=true),
     *             @OA\Property(property="cover_path", type="string", format="uri", maxLength=255, nullable=true),
     *             @OA\Property(property="link", type="string", format="uri", maxLength=255, nullable=true),
     *             @OA\Property(property="started_at", type="string", format="date", nullable=true),
     *             @OA\Property(property="finished_at", type="string", format="date", nullable=true),
     *             @OA\Property(property="publication_year", type="integer", nullable=true),
     *             @OA\Property(property="platform", type="string", enum={"pc", "playstation", "xbox", "nintendo", "mobile", "boardgame", "other"}),
     *             @OA\Property(property="developer", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="publisher", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="playtime_hours", type="integer", minimum=0, nullable=true),
     *             @OA\Property(property="completed", type="boolean", nullable=true),
     *             @OA\Property(property="wishlist", type="boolean", nullable=true),
     *             @OA\Property(property="genres", type="array", @OA\Items(type="integer"), nullable=true),
     *             @OA\Property(property="tags", type="array", @OA\Items(type="string"), nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Game updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Game updated successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryGame")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateLibraryGameRequest $request, LibraryGame $game): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $game), 403);

        $game = $this->libraryGameService->update($game, $request->validated());

        return ApiResponse::success(new LibraryGameResource($game), 'Game updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/api/libraries/games/{game}",
     *     operationId="destroyLibraryGame",
     *     tags={"Libraries - Games"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="game",
     *         in="path",
     *         required=true,
     *         description="Game ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Game deleted successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Game deleted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, LibraryGame $game): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $game), 403);

        $this->libraryGameService->destroy($game);

        return ApiResponse::success(null, 'Game deleted successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/games/import/steam",
     *     operationId="importGameFromSteam",
     *     tags={"Libraries - Games"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"url"},
     *
     *             @OA\Property(property="url", type="string", format="uri", example="https://store.steampowered.com/app/example")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Game imported successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Game imported successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/SteamGameImport")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Game not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function importGameFromSteam(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => 'required|string|url']);

        $game = $this->libraryGameService->importFromSteam($data['url']);

        if (! $game) {
            return ApiResponse::error('Game not found.', 404);
        }

        return ApiResponse::success(new SteamGameImportResource($game), 'Game imported successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/libraries/games/import/bgg",
     *     operationId="importGameFromBgg",
     *     tags={"Libraries - Games"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"url"},
     *
     *             @OA\Property(property="url", type="string", format="uri", example="https://boardgamegeek.com/boardgame/example")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Game imported successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Game imported successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/BggGameImport")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Game not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function importGameFromBgg(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => 'required|string|url']);

        $game = $this->libraryGameService->importFromBgg($data['url']);

        if (! $game) {
            return ApiResponse::error('Game not found.', 404);
        }

        return ApiResponse::success(new BggGameImportResource($game), 'Game imported successfully.');
    }
}
