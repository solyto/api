<?php

namespace App\Api\Calendars\Controllers;

use App\Api\ApiResponse;
use App\Api\Calendars\Notifications\CalendarShareNotification;
use App\Api\Calendars\Requests\ShareCalendarRequest;
use App\Api\Calendars\Requests\StoreCalendarRequest;
use App\Api\Calendars\Requests\StoreEventRequest;
use App\Api\Calendars\Requests\UpdateCalendarRequest;
use App\Api\Calendars\Requests\UpdateEventRequest;
use App\Api\Calendars\Resources\CalendarResource;
use App\Api\Calendars\Resources\EventResource;
use App\Api\Calendars\Services\CalendarService;
use App\Api\Calendars\Services\EventAttachmentService;
use App\Api\HandlesApiAuth;
use App\Api\Users\Models\User;
use App\Dav\DTOs\EventDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarController
{
    use HandlesApiAuth;

    public function __construct(
        private readonly CalendarService $calendarService,
        private readonly EventAttachmentService $attachmentService,
    ) {}

    /**
     * @OA\Get(
     *     path="/calendars",
     *     operationId="listCalendars",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Calendars retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Calendar"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function listCalendars(Request $request): JsonResponse
    {
        return ApiResponse::success(
            CalendarResource::collection($this->calendarService->list($request->user())),
            'Calendars retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/calendars",
     *     operationId="storeCalendar",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name"},
     *
     *             @OA\Property(property="name", type="string", maxLength=255, example="Work Calendar")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Calendar created successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Calendar")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=409, description="Calendar already exists", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function storeCalendar(StoreCalendarRequest $request): JsonResponse
    {
        $data = $request->validated();

        $calendar = $this->calendarService->create($request->user(), $data);

        return ApiResponse::success(new CalendarResource($calendar), 'Calendar created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/calendars/{instanceId}",
     *     operationId="updateCalendarColor",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="instanceId",
     *         in="path",
     *         required=true,
     *         description="Calendar ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="color", type="string", maxLength=255, example="#FF5733")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Calendar updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Calendar")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Calendar not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function updateCalendarColor(UpdateCalendarRequest $request, int $instanceId): JsonResponse
    {
        $calendar = $this->calendarService->getOrFail($request->user(), $instanceId);

        $calendar->color = $request->validated('color');

        $this->calendarService->update($request->user(), $instanceId, $calendar);

        return ApiResponse::success(new CalendarResource($calendar), 'Calendar updated successfully.');
    }

    public function updateCalendarsOrder(Request $request): JsonResponse
    {
        $validated = $request->validate(['order' => 'required|array', 'order.*' => 'integer']);

        $this->calendarService->updateOrder($request->user(), $validated['order']);

        return ApiResponse::success(null, 'Calendar order updated successfully.');
    }

    public function destroyCalendar(Request $request, int $instanceId): JsonResponse
    {
        $calendar = $this->calendarService->getOrFail($request->user(), $instanceId);

        $this->calendarService->destroy($request->user(), $calendar);

        return ApiResponse::success(null, 'Calendar deleted successfully.');
    }

    /**
     * @OA\Get(
     *     path="/calendars/events/{yearMonth}",
     *     operationId="listEvents",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="yearMonth",
     *         in="path",
     *         required=true,
     *         description="Year and month in YYYY-MM format",
     *
     *         @OA\Schema(type="string", example="2024-01")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Calendar events retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Event"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function listEvents(Request $request, string $yearMonth): JsonResponse
    {
        return ApiResponse::success(
            EventResource::collection($this->calendarService->listEvents($request->user(), $yearMonth)),
            'Calendar events retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/calendars/events/widget",
     *     operationId="listWidgetEvents",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Calendar events retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Event"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function listWidgetEvents(Request $request): JsonResponse
    {
        return ApiResponse::success(
            EventResource::collection($this->calendarService->listWidgetEvents($request->user())),
            'Calendar events retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/calendars/{instanceId}/events",
     *     operationId="storeEvent",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="instanceId",
     *         in="path",
     *         required=true,
     *         description="Calendar ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"title", "start_date", "end_date", "is_all_day", "calendar_id"},
     *
     *             @OA\Property(property="title", type="string", maxLength=255, example="Team Meeting"),
     *             @OA\Property(property="start_date", type="string", format="date", example="2024-01-15 10:00:00"),
     *             @OA\Property(property="end_date", type="string", format="date", example="2024-01-15 11:00:00"),
     *             @OA\Property(property="description", type="string", maxLength=255, nullable=true, example="Weekly sync meeting"),
     *             @OA\Property(property="location", type="string", maxLength=255, nullable=true, example="Conference Room A"),
     *             @OA\Property(property="is_all_day", type="boolean", example=false),
     *             @OA\Property(property="calendar_id", type="integer", example=1),
     *             @OA\Property(property="is_recurring", type="boolean", example=false),
     *             @OA\Property(property="recurrence_rule", type="string", nullable=true, example="FREQ=WEEKLY"),
     *             @OA\Property(property="recurrence_end", type="string", format="date", nullable=true, example="2024-12-31")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Event created successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Event")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Calendar not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function storeEvent(StoreEventRequest $request, int $instanceId): JsonResponse
    {
        $calendar = $this->calendarService->getOrFail($request->user(), $instanceId);

        $dto = EventDTO::fromRequest($request->validated(), $calendar, $request->user());
        $event = $this->calendarService->createEvent($request->user(), $calendar, $dto);

        return ApiResponse::success(new EventResource($event), 'Event created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/calendars/{instanceId}/events/{eventUri}",
     *     operationId="updateEvent",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="instanceId",
     *         in="path",
     *         required=true,
     *         description="Calendar ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Parameter(
     *         name="eventUri",
     *         in="path",
     *         required=true,
     *         description="Event URI",
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"title", "start_date", "end_date", "is_all_day", "calendar_id", "etag"},
     *
     *             @OA\Property(property="title", type="string", maxLength=255, example="Team Meeting"),
     *             @OA\Property(property="start_date", type="string", format="date", example="2024-01-15 10:00:00"),
     *             @OA\Property(property="end_date", type="string", format="date", example="2024-01-15 11:00:00"),
     *             @OA\Property(property="description", type="string", maxLength=255, nullable=true, example="Weekly sync meeting"),
     *             @OA\Property(property="location", type="string", maxLength=255, nullable=true, example="Conference Room A"),
     *             @OA\Property(property="is_all_day", type="boolean", example=false),
     *             @OA\Property(property="calendar_id", type="integer", example=1),
     *             @OA\Property(property="is_recurring", type="boolean", example=false),
     *             @OA\Property(property="recurrence_rule", type="string", nullable=true, example="FREQ=WEEKLY"),
     *             @OA\Property(property="recurrence_end", type="string", format="date", nullable=true, example="2024-12-31"),
     *             @OA\Property(property="occurrence_start_date", type="string", format="date", nullable=true, example="2024-01-17 10:00:00", description="For a series edit: the listed start of the occurrence that was edited. The series moves by the difference between start_date and this value; without it, start_date becomes the series start."),
     *             @OA\Property(property="etag", type="string", example="abc123")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Event updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Event")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unable to update event", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Calendar or Event not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function updateEvent(UpdateEventRequest $request, int $instanceId, string $eventUri): JsonResponse
    {
        $calendar = $this->calendarService->getOrFail($request->user(), $instanceId);

        $event = $this->calendarService->getEvent($calendar, $eventUri);

        if ($event === null) {
            return ApiResponse::error('Event does not exist', 404);
        }

        $targetCalendarId = (int) $request->validated('calendar_id');

        if ($targetCalendarId !== $instanceId) {
            $targetCalendar = $this->calendarService->get($request->user(), $targetCalendarId);

            if ($targetCalendar === null) {
                return ApiResponse::error('Target calendar does not exist', 404);
            }

            $event->updateFromRequest($request, $request->user());

            $newEvent = $this->calendarService->moveEvent($request->user(), $calendar, $targetCalendar, $event);

            if ($newEvent === null) {
                return ApiResponse::error('Unable to move event to new calendar', 500);
            }

            return ApiResponse::success(new EventResource($newEvent), 'Event moved successfully.');
        }

        $event->updateFromRequest($request, $request->user());

        $event = $this->calendarService->updateEvent($request->user(), $calendar, $event);

        if ($event === null) {
            return ApiResponse::error('Unable to update event', 403);
        }

        return ApiResponse::success(new EventResource($event), 'Event updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/calendars/{instanceId}/events/{eventUri}",
     *     operationId="destroyEvent",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="instanceId",
     *         in="path",
     *         required=true,
     *         description="Calendar ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Parameter(
     *         name="eventUri",
     *         in="path",
     *         required=true,
     *         description="Event URI",
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Event deleted successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Calendar or Event not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroyEvent(Request $request, int $instanceId, string $eventUri): JsonResponse
    {
        $calendar = $this->calendarService->getOrFail($request->user(), $instanceId);

        $event = $this->calendarService->getEvent($calendar, $eventUri);

        if ($event === null) {
            return ApiResponse::error('Event does not exist', 404);
        }

        if (! $this->calendarService->destroyEvent($request->user(), $calendar, $event)) {
            return ApiResponse::error('Error deleting event', 500);
        }

        $this->attachmentService->deleteAllForEvent($request->user(), (string) $event->id);

        return ApiResponse::success(null, 'Event deleted successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/calendars/{instanceId}/events/{eventUri}/occurrence/{occurrenceDate}",
     *     operationId="destroyEventOccurrence",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="instanceId",
     *         in="path",
     *         required=true,
     *         description="Calendar ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Parameter(
     *         name="eventUri",
     *         in="path",
     *         required=true,
     *         description="Event URI",
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\Parameter(
     *         name="occurrenceDate",
     *         in="path",
     *         required=true,
     *         description="Occurrence date",
     *
     *         @OA\Schema(type="string", format="date", example="2024-01-15")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Occurrence deleted successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=400, description="Event is not recurring", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Calendar or Event not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroyEventOccurrence(Request $request, int $instanceId, string $eventUri, string $occurrenceDate): JsonResponse
    {
        $calendar = $this->calendarService->getOrFail($request->user(), $instanceId);

        $event = $this->calendarService->getEvent($calendar, $eventUri);

        if ($event === null) {
            return ApiResponse::error('Event does not exist', 404);
        }

        if ($event->recurrenceRule === null) {
            return ApiResponse::error('Event is not recurring', 400);
        }

        // The frontend sends UTC (toISOString()); all-day slots need the user's local date.
        $userTz = new \DateTimeZone($request->user()->settings->timezone);
        $parsedDate = (new \DateTime($occurrenceDate, $userTz))->setTimezone($userTz);

        if (! $this->calendarService->destroyEventOccurrence($request->user(), $calendar, $eventUri, $parsedDate)) {
            return ApiResponse::error('Error deleting occurrence', 500);
        }

        return ApiResponse::success(null, 'Occurrence deleted successfully.');
    }

    /**
     * @OA\Put(
     *     path="/calendars/{instanceId}/events/{eventUri}/occurrence/{occurrenceDate}",
     *     operationId="updateEventOccurrence",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="instanceId",
     *         in="path",
     *         required=true,
     *         description="Calendar ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Parameter(
     *         name="eventUri",
     *         in="path",
     *         required=true,
     *         description="Event URI",
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\Parameter(
     *         name="occurrenceDate",
     *         in="path",
     *         required=true,
     *         description="Occurrence date",
     *
     *         @OA\Schema(type="string", format="date", example="2024-01-15")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"title", "start_date", "end_date", "is_all_day", "calendar_id", "etag"},
     *
     *             @OA\Property(property="title", type="string", maxLength=255, example="Team Meeting"),
     *             @OA\Property(property="start_date", type="string", format="date", example="2024-01-15 10:00:00"),
     *             @OA\Property(property="end_date", type="string", format="date", example="2024-01-15 11:00:00"),
     *             @OA\Property(property="description", type="string", maxLength=255, nullable=true, example="Weekly sync meeting"),
     *             @OA\Property(property="location", type="string", maxLength=255, nullable=true, example="Conference Room A"),
     *             @OA\Property(property="is_all_day", type="boolean", example=false),
     *             @OA\Property(property="calendar_id", type="integer", example=1),
     *             @OA\Property(property="is_recurring", type="boolean", example=false),
     *             @OA\Property(property="recurrence_rule", type="string", nullable=true, example="FREQ=WEEKLY"),
     *             @OA\Property(property="recurrence_end", type="string", format="date", nullable=true, example="2024-12-31"),
     *             @OA\Property(property="etag", type="string", example="abc123")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Occurrence updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=400, description="Event is not recurring", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Calendar or Event not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function updateEventOccurrence(UpdateEventRequest $request, int $instanceId, string $eventUri, string $occurrenceDate): JsonResponse
    {
        $calendar = $this->calendarService->getOrFail($request->user(), $instanceId);

        $event = $this->calendarService->getEvent($calendar, $eventUri);

        if ($event === null) {
            return ApiResponse::error('Event does not exist', 404);
        }

        if ($event->recurrenceRule === null) {
            return ApiResponse::error('Event is not recurring', 400);
        }

        // The frontend sends UTC (toISOString()); all-day slots need the user's local date.
        $userTz = new \DateTimeZone($request->user()->settings->timezone);
        $parsedDate = (new \DateTime($occurrenceDate, $userTz))->setTimezone($userTz);
        $dto = EventDTO::fromRequest($request->validated(), $calendar, $request->user());

        if (! $this->calendarService->updateEventOccurrence($request->user(), $calendar, $eventUri, $parsedDate, $dto)) {
            return ApiResponse::error('Error updating occurrence', 500);
        }

        return ApiResponse::success(null, 'Occurrence updated successfully.');
    }

    /**
     * @OA\Get(
     *     path="/calendars/{instanceId}/share",
     *     operationId="listSharees",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="instanceId",
     *         in="path",
     *         required=true,
     *         description="Calendar ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Sharees retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *
     *                 @OA\Items(
     *
     *                     @OA\Property(property="user_id", type="string", format="uuid"),
     *                     @OA\Property(property="user_name", type="string"),
     *                     @OA\Property(property="user_email", type="string", format="email"),
     *                     @OA\Property(property="access", type="integer"),
     *                     @OA\Property(property="status", type="string", enum={"pending", "accepted", "declined"})
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="You can only list sharees for calendars you own", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Calendar not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function listSharees(Request $request, int $instanceId): JsonResponse
    {
        $calendar = $this->calendarService->getOrFail($request->user(), $instanceId);

        if ($calendar->isShared) {
            return ApiResponse::error('You can only list sharees for calendars you own', 403);
        }

        return ApiResponse::success($this->calendarService->listSharees($calendar), 'Sharees retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/calendars/{instanceId}/share",
     *     operationId="shareCalendar",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="instanceId",
     *         in="path",
     *         required=true,
     *         description="Calendar ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"friend_id"},
     *
     *             @OA\Property(property="friend_id", type="string", format="uuid", example="550e8400-e29b-41d4-a716-446655440000")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Calendar shared successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="You can only share calendars you own", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Calendar or User not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function shareCalendar(ShareCalendarRequest $request, int $instanceId): JsonResponse
    {
        $calendar = $this->calendarService->getOrFail($request->user(), $instanceId);

        if ($calendar->isShared) {
            return ApiResponse::error('You can only share calendars you own', 403);
        }

        $recipient = User::find($request->validated('friend_id'));

        if ($recipient === null) {
            return ApiResponse::error('User does not exist', 404);
        }

        $this->calendarService->share($calendar, $request->user(), $recipient);
        $recipient->notify(new CalendarShareNotification($calendar->displayName, $request->user()->name));

        return ApiResponse::success(null, 'Calendar shared successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/calendars/{instanceId}/share/{userId}",
     *     operationId="revokeShare",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="instanceId",
     *         in="path",
     *         required=true,
     *         description="Calendar ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Parameter(
     *         name="userId",
     *         in="path",
     *         required=true,
     *         description="User ID to revoke share from",
     *
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Share revoked successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="You can only manage shares for calendars you own", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Calendar or User not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function revokeShare(Request $request, int $instanceId, string $userId): JsonResponse
    {
        $calendar = $this->calendarService->getOrFail($request->user(), $instanceId);

        if ($calendar->isShared) {
            return ApiResponse::error('You can only manage shares for calendars you own', 403);
        }

        $recipient = User::find($userId);

        if ($recipient === null) {
            return ApiResponse::error('User does not exist', 404);
        }

        $this->calendarService->revokeShare($calendar, $recipient);

        return ApiResponse::success(null, 'Share revoked successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/calendars/{instanceId}/unsubscribe",
     *     operationId="unsubscribeFromCalendar",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="instanceId",
     *         in="path",
     *         required=true,
     *         description="Calendar ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Unsubscribed from calendar successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="You can only unsubscribe from shared calendars", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Calendar not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function unsubscribeFromCalendar(Request $request, int $instanceId): JsonResponse
    {
        $calendar = $this->calendarService->getOrFail($request->user(), $instanceId);

        if (! $calendar->isShared) {
            return ApiResponse::error('You can only unsubscribe from shared calendars', 403);
        }

        $this->calendarService->unsubscribe($request->user(), $calendar);

        return ApiResponse::success(null, 'Unsubscribed from calendar successfully.');
    }

    /**
     * @OA\Get(
     *     path="/calendars/invites",
     *     operationId="listInvites",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Calendar invites retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Calendar"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function listInvites(Request $request): JsonResponse
    {
        return ApiResponse::success(
            CalendarResource::collection($this->calendarService->listInvites($request->user())),
            'Calendar invites retrieved successfully.'
        );
    }

    /**
     * @OA\Put(
     *     path="/calendars/invites/{token}/accept",
     *     operationId="acceptInvite",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="token",
     *         in="path",
     *         required=true,
     *         description="Invite token",
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Invite accepted successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Invite not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function acceptInvite(Request $request, string $token): JsonResponse
    {
        $this->calendarService->acceptInvite($request->user(), $token);

        return ApiResponse::success(null, 'Invite accepted successfully.');
    }

    /**
     * @OA\Put(
     *     path="/calendars/invites/{token}/decline",
     *     operationId="declineInvite",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="token",
     *         in="path",
     *         required=true,
     *         description="Invite token",
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Invite declined successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Invite not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function declineInvite(Request $request, string $token): JsonResponse
    {
        $this->calendarService->declineInvite($request->user(), $token);

        return ApiResponse::success(null, 'Invite declined successfully.');
    }
}
