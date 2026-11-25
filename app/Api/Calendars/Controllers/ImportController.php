<?php

namespace App\Api\Calendars\Controllers;

use App\Api\ApiResponse;
use App\Api\Calendars\Jobs\ImportCalendarFile;
use App\Api\Calendars\Jobs\ImportCalendars;
use App\Api\Calendars\Requests\ImportCalendarFileRequest;
use App\Api\Calendars\Requests\SelectImportCalendars;
use App\Api\Calendars\Requests\StartImportRequest;
use App\Api\Calendars\Resources\ImportStateResource;
use App\Api\Calendars\Services\CalendarService;
use App\Api\HandlesApiAuth;
use App\Dav\Services\DavService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportController
{
    use HandlesApiAuth;

    public function __construct(
        private readonly DavService $dav,
        private readonly CalendarService $calendarService,
    ) {}

    /**
     * @OA\Post(
     *     path="/calendars/import",
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
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Could not grab calendars", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=409, description="A calendar import is already running", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function startImport(StartImportRequest $request): JsonResponse
    {
        $validatedData = $request->validated();
        $this->dav->import()->calendars()->start($request->user(), $validatedData['url'], $validatedData['username'], $validatedData['secret']);

        return ApiResponse::success([], 'Import started successfully.');
    }

    /**
     * @OA\Post(
     *     path="/calendars/import/select",
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
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Import state not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=409, description="A calendar import is already running", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
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

        ImportCalendars::dispatch($request->user()->id, $dto->jobId);

        return ApiResponse::success([], 'Import calendars selected successfully.');
    }

    /**
     * @OA\Post(
     *     path="/calendars/import/file",
     *     operationId="startCalendarFileImport",
     *     tags={"Calendars"},
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
     *                 @OA\Property(property="file", type="string", format="binary", description="iCalendar file (.ics), at most 10 MB and 5000 events"),
     *                 @OA\Property(property="calendar_id", type="integer", nullable=true, description="Own calendar to import into; without it a new calendar is created"),
     *                 @OA\Property(property="name", type="string", maxLength=100, nullable=true, description="New calendar only; defaults to the file's X-WR-CALNAME, then the file name"),
     *                 @OA\Property(property="color", type="string", pattern="^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$", nullable=true, description="New calendar only", example="#0088CC")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Import started successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/CalendarImportState")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Shared and external calendars cannot be imported into", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Calendar does not exist", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=409, description="A calendar import is already running", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error (ValidationErrorResponse), or the file is not readable iCalendar, has no events or more than 5000 (ErrorResponse)",
     *
     *         @OA\JsonContent(oneOf={@OA\Schema(ref="#/components/schemas/ValidationErrorResponse"), @OA\Schema(ref="#/components/schemas/ErrorResponse")})
     *     ),
     *
     *     @OA\Response(response=429, description="Too many requests", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function importFile(ImportCalendarFileRequest $request): JsonResponse
    {
        $calendarId = $request->validated('calendar_id');
        $calendar = $calendarId !== null ? $this->calendarService->getOrFail($request->user(), (int) $calendarId) : null;

        $dto = $this->dav->import()->calendars()->startFile(
            $request->user(),
            $request->file('file'),
            $calendar,
            $request->validated('name'),
            $request->validated('color'),
        );

        ImportCalendarFile::dispatch($request->user()->id, $dto->jobId);

        return ApiResponse::success(new ImportStateResource($dto), 'Import started successfully.');
    }

    /**
     * @OA\Get(
     *     path="/calendars/import/state",
     *     operationId="getCalendarsImportState",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Import state retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
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
