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
     *     path="/api/calendars",
     *     operationId="listCalendars",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Calendars retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Calendars retrieved successfully."),
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
     *     path="/api/calendars",
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
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Calendar created successfully."),
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

        if ($this->calendarService->getByName($request->user(), $data['name']) !== null) {
            return ApiResponse::error('Calendar already exists', 409);
        }

        try {
            $calendar = $this->calendarService->create($request->user(), $data);
        } catch (\Exception $e) {
            report($e);
            return ApiResponse::error('Error creating calendar', 500);
        }

        return ApiResponse::success(new CalendarResource($calendar), 'Calendar created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/api/calendars/{instanceId}",
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
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Calendar updated successfully."),
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
        $calendar = $this->calendarService->get($request->user(), $instanceId);

        if ($calendar === null) {
            return ApiResponse::error('Calendar does not exist', 404);
        }

        $calendar->color = $request->validated('color');

        try {
            $this->calendarService->update($request->user(), $instanceId, $calendar);
        } catch (\Exception $e) {
            report($e);
            return ApiResponse::error('Error updating calendar', 500);
        }

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
        $calendar = $this->calendarService->get($request->user(), $instanceId);

        if ($calendar === null) {
            return ApiResponse::error('Calendar does not exist', 404);
        }

        $this->calendarService->destroy($request->user(), $calendar);

        return ApiResponse::success(null, 'Calendar deleted successfully.');
    }

    /**
     * @OA\Get(
     *     path="/api/calendars/events/{yearMonth}",
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
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Calendar events retrieved successfully."),
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
     *     path="/api/calendars/widget/events",
     *     operationId="listWidgetEvents",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Calendar events retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Calendar events retrieved successfully."),
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
     *     path="/api/calendars/{instanceId}/events",
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
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Event created successfully."),
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
        $calendar = $this->calendarService->get($request->user(), $instanceId);

        if ($calendar === null) {
            return ApiResponse::error('Calendar does not exist', 404);
        }

        try {
            $dto = EventDTO::fromRequest($request->validated(), $calendar, $request->user());
            $event = $this->calendarService->createEvent($request->user(), $calendar, $dto);
        } catch (\Exception $e) {
            report($e);
            return ApiResponse::error('Error creating event', 500);
        }

        return ApiResponse::success(new EventResource($event), 'Event created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/api/calendars/{instanceId}/events/{eventUri}",
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
     *             @OA\Property(property="etag", type="string", example="abc123")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Event updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Event updated successfully."),
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
        $calendar = $this->calendarService->get($request->user(), $instanceId);

        if ($calendar === null) {
            return ApiResponse::error('Calendar does not exist', 404);
        }

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

            try {
                $newEvent = $this->calendarService->moveEvent($request->user(), $calendar, $targetCalendar, $event);
        } catch (\Exception $e) {
            report($e);
            return ApiResponse::error('Error moving event', 500);
            }

            if ($newEvent === null) {
                return ApiResponse::error('Unable to move event to new calendar', 500);
            }

            return ApiResponse::success(new EventResource($newEvent), 'Event moved successfully.');
        }

        $event->updateFromRequest($request, $request->user());

        try {
            $event = $this->calendarService->updateEvent($request->user(), $calendar, $event);
        } catch (\Exception $e) {
            report($e);
            return ApiResponse::error('Error updating event', 500);
        }

        if ($event === null) {
            return ApiResponse::error('Unable to update event', 403);
        }

        return ApiResponse::success(new EventResource($event), 'Event updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/api/calendars/{instanceId}/events/{eventUri}",
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
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Event deleted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Calendar or Event not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroyEvent(Request $request, int $instanceId, string $eventUri): JsonResponse
    {
        $calendar = $this->calendarService->get($request->user(), $instanceId);

        if ($calendar === null) {
            return ApiResponse::error('Calendar does not exist', 404);
        }

        $event = $this->calendarService->getEvent($calendar, $eventUri);

        if ($event === null) {
            return ApiResponse::error('Event does not exist', 404);
        }

        if (! $this->calendarService->destroyEvent($request->user(), $calendar, $event)) {
            return ApiResponse::error('Error deleting event', 500);
        }

        $this->attachmentService->deleteAllForEvent($request->user(), $event->id);

        return ApiResponse::success(null, 'Event deleted successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/api/calendars/{instanceId}/events/{eventUri}/occurrences/{occurrenceDate}",
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
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Occurrence deleted successfully.")
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
        $calendar = $this->calendarService->get($request->user(), $instanceId);

        if ($calendar === null) {
            return ApiResponse::error('Calendar does not exist', 404);
        }

        $event = $this->calendarService->getEvent($calendar, $eventUri);

        if ($event === null) {
            return ApiResponse::error('Event does not exist', 404);
        }

        if ($event->recurrenceRule === null) {
            return ApiResponse::error('Event is not recurring', 400);
        }

        $parsedDate = new \DateTime($occurrenceDate, new \DateTimeZone($request->user()->settings->timezone));

        if (! $this->calendarService->destroyEventOccurrence($request->user(), $calendar, $eventUri, $parsedDate)) {
            return ApiResponse::error('Error deleting occurrence', 500);
        }

        return ApiResponse::success(null, 'Occurrence deleted successfully.');
    }

    /**
     * @OA\Put(
     *     path="/api/calendars/{instanceId}/events/{eventUri}/occurrences/{occurrenceDate}",
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
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Occurrence updated successfully.")
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
        $calendar = $this->calendarService->get($request->user(), $instanceId);

        if ($calendar === null) {
            return ApiResponse::error('Calendar does not exist', 404);
        }

        $event = $this->calendarService->getEvent($calendar, $eventUri);

        if ($event === null) {
            return ApiResponse::error('Event does not exist', 404);
        }

        if ($event->recurrenceRule === null) {
            return ApiResponse::error('Event is not recurring', 400);
        }

        $parsedDate = new \DateTime($occurrenceDate, new \DateTimeZone($request->user()->settings->timezone));
        $dto = EventDTO::fromRequest($request->validated(), $calendar, $request->user());

        if (! $this->calendarService->updateEventOccurrence($request->user(), $calendar, $eventUri, $parsedDate, $dto)) {
            return ApiResponse::error('Error updating occurrence', 500);
        }

        return ApiResponse::success(null, 'Occurrence updated successfully.');
    }

    /**
     * @OA\Get(
     *     path="/api/calendars/{instanceId}/share",
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
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Sharees retrieved successfully."),
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
        $calendar = $this->calendarService->get($request->user(), $instanceId);

        if ($calendar === null) {
            return ApiResponse::error('Calendar does not exist', 404);
        }

        if ($calendar->isShared) {
            return ApiResponse::error('You can only list sharees for calendars you own', 403);
        }

        return ApiResponse::success($this->calendarService->listSharees($calendar), 'Sharees retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/calendars/{instanceId}/share",
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
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Calendar shared successfully.")
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
        $calendar = $this->calendarService->get($request->user(), $instanceId);

        if ($calendar === null) {
            return ApiResponse::error('Calendar does not exist', 404);
        }

        if ($calendar->isShared) {
            return ApiResponse::error('You can only share calendars you own', 403);
        }

        $recipient = User::find($request->validated('friend_id'));

        if ($recipient === null) {
            return ApiResponse::error('User does not exist', 404);
        }

        try {
            $this->calendarService->share($calendar, $request->user(), $recipient);
            $recipient->notify(new CalendarShareNotification($calendar->displayName, $request->user()->name));
        } catch (\Exception $e) {
            report($e);
            return ApiResponse::error('Error sharing calendar', 500);
        }

        return ApiResponse::success(null, 'Calendar shared successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/api/calendars/{instanceId}/share/{userId}",
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
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Share revoked successfully.")
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
        $calendar = $this->calendarService->get($request->user(), $instanceId);

        if ($calendar === null) {
            return ApiResponse::error('Calendar does not exist', 404);
        }

        if ($calendar->isShared) {
            return ApiResponse::error('You can only manage shares for calendars you own', 403);
        }

        $recipient = User::find($userId);

        if ($recipient === null) {
            return ApiResponse::error('User does not exist', 404);
        }

        try {
            $this->calendarService->revokeShare($calendar, $recipient);
        } catch (\Exception $e) {
            report($e);
            return ApiResponse::error('Error revoking share', 500);
        }

        return ApiResponse::success(null, 'Share revoked successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/calendars/{instanceId}/unsubscribe",
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
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Unsubscribed from calendar successfully.")
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
        $calendar = $this->calendarService->get($request->user(), $instanceId);

        if ($calendar === null) {
            return ApiResponse::error('Calendar does not exist', 404);
        }

        if (! $calendar->isShared) {
            return ApiResponse::error('You can only unsubscribe from shared calendars', 403);
        }

        try {
            $this->calendarService->unsubscribe($request->user(), $calendar);
        } catch (\Exception $e) {
            report($e);
            return ApiResponse::error('Error unsubscribing from calendar', 500);
        }

        return ApiResponse::success(null, 'Unsubscribed from calendar successfully.');
    }

    /**
     * @OA\Get(
     *     path="/api/calendars/invites",
     *     operationId="listInvites",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Calendar invites retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Calendar invites retrieved successfully."),
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
     * @OA\Post(
     *     path="/api/calendars/invites/{token}/accept",
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
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Invite accepted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Invite not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function acceptInvite(Request $request, string $token): JsonResponse
    {
        try {
            $this->calendarService->acceptInvite($request->user(), $token);
        } catch (\Exception $e) {
            report($e);
            return ApiResponse::error('Error accepting invite', 500);
        }

        return ApiResponse::success(null, 'Invite accepted successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/calendars/invites/{token}/decline",
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
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Invite declined successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Invite not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function declineInvite(Request $request, string $token): JsonResponse
    {
        try {
            $this->calendarService->declineInvite($request->user(), $token);
        } catch (\Exception $e) {
            report($e);
            return ApiResponse::error('Error declining invite', 500);
        }

        return ApiResponse::success(null, 'Invite declined successfully.');
    }
}
