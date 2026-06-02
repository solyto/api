<?php

namespace App\Api\Calendars\Controllers;

use App\Api\ApiResponse;
use App\Api\Calendars\Services\EventAttachmentService;
use App\Api\HandlesApiAuth;
use App\Api\Notes\Models\Note;
use App\Api\Notes\Resources\NoteResource;
use App\Api\Todos\Models\Todo;
use App\Api\Todos\Resources\TodoResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventAttachmentController
{
    use HandlesApiAuth;

    public function __construct(private readonly EventAttachmentService $attachmentService) {}

    /**
     * @OA\Get(
     *     path="/v1/calendars/events/{eventId}/attachments/todos",
     *     operationId="getEventTodoAttachments",
     *     summary="Get todos attached to a calendar event",
     *     tags={"Calendar Attachments"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="eventId",
     *         in="path",
     *         description="Calendar object ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Todo attachments retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Todo"))
     *         )
     *     )
     * )
     */
    public function getTodoAttachments(Request $request, int $eventId): JsonResponse
    {
        return ApiResponse::success(
            TodoResource::collection($this->attachmentService->getTodoAttachments($request->user(), $eventId)),
            'Todo attachments retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/v1/calendars/events/{eventId}/attachments/notes",
     *     operationId="getEventNoteAttachments",
     *     summary="Get notes attached to a calendar event",
     *     tags={"Calendar Attachments"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="eventId",
     *         in="path",
     *         description="Calendar object ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Note attachments retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Note"))
     *         )
     *     )
     * )
     */
    public function getNoteAttachments(Request $request, int $eventId): JsonResponse
    {
        return ApiResponse::success(
            NoteResource::collection($this->attachmentService->getNoteAttachments($request->user(), $eventId)),
            'Note attachments retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/v1/calendars/events/{eventId}/attachments/todos",
     *     operationId="attachTodoToEvent",
     *     summary="Attach a todo to a calendar event",
     *     tags={"Calendar Attachments"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="eventId",
     *         in="path",
     *         description="Calendar object ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"todo_id"},
     *
     *             @OA\Property(property="todo_id", type="string", format="uuid")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Todo attached successfully",
     *
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")})
     *     ),
     *
     *     @OA\Response(response=404, description="Todo not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=409, description="Todo already attached", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function attachTodo(Request $request, int $eventId): JsonResponse
    {
        $request->validate(['todo_id' => 'required|uuid']);

        $todo = Todo::forUser($request->user()->id)->find($request->todo_id);
        if (!$todo) {
            return ApiResponse::notFound('Todo not found.');
        }

        if (!$this->attachmentService->attachTodo($request->user(), $eventId, $todo)) {
            return ApiResponse::error('Todo already attached.', 409);
        }

        return ApiResponse::success(null, 'Todo attached successfully.', 201);
    }

    /**
     * @OA\Delete(
     *     path="/v1/calendars/events/{eventId}/attachments/todos/{todoId}",
     *     operationId="detachTodoFromEvent",
     *     summary="Detach a todo from a calendar event",
     *     tags={"Calendar Attachments"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="eventId",
     *         in="path",
     *         description="Calendar object ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Parameter(
     *         name="todoId",
     *         in="path",
     *         description="Todo ID",
     *         required=true,
     *
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Todo detached successfully",
     *
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")})
     *     )
     * )
     */
    public function detachTodo(Request $request, int $eventId, string $todoId): JsonResponse
    {
        $this->attachmentService->detachTodo($request->user(), $eventId, $todoId);

        return ApiResponse::success(null, 'Todo detached successfully.');
    }

    /**
     * @OA\Post(
     *     path="/v1/calendars/events/{eventId}/attachments/notes",
     *     operationId="attachNoteToEvent",
     *     summary="Attach a note to a calendar event",
     *     tags={"Calendar Attachments"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="eventId",
     *         in="path",
     *         description="Calendar object ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"note_id"},
     *
     *             @OA\Property(property="note_id", type="string", format="uuid")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Note attached successfully",
     *
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")})
     *     ),
     *
     *     @OA\Response(response=404, description="Note not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=409, description="Note already attached", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function attachNote(Request $request, int $eventId): JsonResponse
    {
        $request->validate(['note_id' => 'required|uuid']);

        $note = Note::forUser($request->user()->id)->find($request->note_id);
        if (!$note) {
            return ApiResponse::notFound('Note not found.');
        }

        if (!$this->attachmentService->attachNote($request->user(), $eventId, $note)) {
            return ApiResponse::error('Note already attached.', 409);
        }

        return ApiResponse::success(null, 'Note attached successfully.', 201);
    }

    /**
     * @OA\Delete(
     *     path="/v1/calendars/events/{eventId}/attachments/notes/{noteId}",
     *     operationId="detachNoteFromEvent",
     *     summary="Detach a note from a calendar event",
     *     tags={"Calendar Attachments"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="eventId",
     *         in="path",
     *         description="Calendar object ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Parameter(
     *         name="noteId",
     *         in="path",
     *         description="Note ID",
     *         required=true,
     *
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Note detached successfully",
     *
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")})
     *     )
     * )
     */
    public function detachNote(Request $request, int $eventId, string $noteId): JsonResponse
    {
        $this->attachmentService->detachNote($request->user(), $eventId, $noteId);

        return ApiResponse::success(null, 'Note detached successfully.');
    }
}
