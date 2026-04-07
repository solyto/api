<?php

namespace App\Api\Calendars\Controllers;

use App\Api\ApiResponse;
use App\Api\Calendars\Jobs\ImportCalendars;
use App\Api\Calendars\Requests\SelectImportCalendars;
use App\Api\Calendars\Requests\StartImportRequest;
use App\Api\Calendars\Resources\ImportStateResource;
use App\Api\HandlesApiAuth;
use App\Dav\Exceptions\ImportException;
use App\Dav\Services\DavService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportController
{
    use HandlesApiAuth;

    private DavService $dav;

    public function __construct()
    {
        $this->dav = app(DavService::class);
    }

    /**
     * @OA\Post(
     *     path="/api/calendars/import/start",
     *     operationId="startCalendarsImport",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"url", "username", "secret"},
     *
     *             @OA\Property(property="url", type="string", format="uri", maxLength=255, example="https://caldav.example.com/calendars/"),
     *             @OA\Property(property="username", type="string", maxLength=255, example="user@example.com"),
     *             @OA\Property(property="secret", type="string", maxLength=255, example="app-password")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Import started successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Import started successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Could not grab calendars", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function startImport(StartImportRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validated();
            $this->dav->import()->calendars()->start($request->user(), $validatedData['url'], $validatedData['username'], $validatedData['secret']);
        } catch (ImportException $e) {
            return ApiResponse::error('Could not grab calendars', 404);
        }

        return ApiResponse::success([], 'Import started successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/calendars/import/select",
     *     operationId="selectCalendarsForImport",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"calendars"},
     *
     *             @OA\Property(property="calendars", type="array", @OA\Items(type="string"), example={"/calendar1.ics", "/calendar2.ics"})
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Import calendars selected successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Import calendars selected successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Import state not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function selectCalendars(SelectImportCalendars $request): JsonResponse
    {
        $dto = $this->dav->import()->calendars()->getState($request->user()->id);

        if (! $dto) {
            return ApiResponse::error('Import state not found', 404);
        }

        $this->dav->import()->calendars()->selectCalendars($request->user(), $dto, $request->validated()['calendars']);

        ImportCalendars::dispatch($request->user());

        return ApiResponse::success([], 'Import calendars selected successfully.');
    }

    /**
     * @OA\Get(
     *     path="/api/calendars/import/state",
     *     operationId="getCalendarsImportState",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Import state retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Import state retrieved successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/CalendarImportState")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Import state not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function getState(Request $request): JsonResponse
    {
        $dto = $this->dav->import()->calendars()->getState($request->user()->id);

        if (! $dto) {
            return ApiResponse::error('Import state not found', 404);
        }

        return ApiResponse::success(
            new ImportStateResource($dto),
            'Import state retrieved successfully.'
        );
    }
}
