<?php

namespace App\Api\Notes\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Notes\Models\Note;
use App\Api\Notes\Requests\StoreNoteRequest;
use App\Api\Notes\Requests\UpdateNoteRequest;
use App\Api\Notes\Resources\NoteResource;
use App\Api\Notes\Services\NoteService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class NoteController
{
    use HandlesApiAuth;

    public function __construct(private readonly NoteService $noteService) {}

    /**
     * @OA\Get(
     *     path="/notes",
     *     operationId="noteIndex",
     *     summary="List notes",
     *     tags={"Notes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Notes retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Note"))
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            NoteResource::collection($this->noteService->list($request->user())),
            'Notes retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/notes/hidden",
     *     operationId="noteHidden",
     *     summary="List notes in hidden and system categories",
     *     description="The notes GET /notes leaves out: those in a category returned by GET /notes/categories/hidden.",
     *     tags={"Notes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Hidden notes retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Note"))
     *         )
     *     )
     * )
     */
    public function hidden(Request $request): JsonResponse
    {
        return ApiResponse::success(
            NoteResource::collection($this->noteService->listHidden($request->user())),
            'Hidden notes retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/notes/newest",
     *     operationId="noteNewest",
     *     summary="List newest notes",
     *     tags={"Notes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Notes retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Note"))
     *         )
     *     )
     * )
     */
    public function newest(Request $request): JsonResponse
    {
        return ApiResponse::success(
            NoteResource::collection($this->noteService->newest($request->user())),
            'Notes retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/notes/journal/marks",
     *     operationId="noteJournalMarks",
     *     summary="List dates with a journal entry",
     *     tags={"Notes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="from", in="query", required=true, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="to", in="query", required=true, @OA\Schema(type="string", format="date")),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Journal marks retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(type="string", format="date"))
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function journalMarks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ]);

        if (Carbon::parse($validated['from'])->diffInDays(Carbon::parse($validated['to'])) > 366) {
            throw ValidationException::withMessages([
                'to' => 'The date range must not exceed 366 days.',
            ]);
        }

        return ApiResponse::success(
            $this->noteService->journalMarks($request->user(), $validated['from'], $validated['to']),
            'Journal marks retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/notes/{note}",
     *     operationId="noteShow",
     *     summary="Get note by ID",
     *     tags={"Notes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="note",
     *         in="path",
     *         description="Note ID",
     *         required=true,
     *
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Note retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Note")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, Note $note): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $note), 403);

        return ApiResponse::success(new NoteResource($note), 'Note retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/notes",
     *     operationId="noteStore",
     *     summary="Create a new note",
     *     tags={"Notes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"title"},
     *
     *             @OA\Property(property="title", type="string"),
     *             @OA\Property(property="content", type="string", nullable=true),
     *             @OA\Property(property="category_id", type="integer", nullable=true),
     *             @OA\Property(property="tags", type="array", @OA\Items(type="integer"), nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Note created successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Note")
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function store(StoreNoteRequest $request): JsonResponse
    {
        $note = $this->noteService->create($request->user(), $request->validated());

        return ApiResponse::success(new NoteResource($note), 'Note created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/notes/{note}",
     *     operationId="noteUpdate",
     *     summary="Update a note",
     *     tags={"Notes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="note",
     *         in="path",
     *         description="Note ID",
     *         required=true,
     *
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="title", type="string"),
     *             @OA\Property(property="content", type="string", nullable=true),
     *             @OA\Property(property="category_id", type="integer", nullable=true),
     *             @OA\Property(property="tags", type="array", @OA\Items(type="integer"), nullable=true),
     *             @OA\Property(property="is_favorite", type="boolean")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Note updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Note")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateNoteRequest $request, Note $note): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $note), 403);

        $note = $this->noteService->update($note, $request->validated());

        return ApiResponse::success(new NoteResource($note), 'Note updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/notes/{note}",
     *     operationId="noteDestroy",
     *     summary="Delete a note",
     *     tags={"Notes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="note",
     *         in="path",
     *         description="Note ID",
     *         required=true,
     *
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Note deleted successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, Note $note): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $note), 403);

        $this->noteService->destroy($note);

        return ApiResponse::success(null, 'Note deleted successfully.');
    }
}
