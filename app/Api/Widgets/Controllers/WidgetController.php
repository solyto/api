<?php

namespace App\Api\Widgets\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Todos\Models\Todo;
use App\Api\Widgets\Resources\YourDayResource;
use App\Api\Widgets\Services\WidgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WidgetController
{
    use HandlesApiAuth;

    public function __construct(private readonly WidgetService $widgetService) {}

    /**
     * @OA\Get(
     *     path="/widgets/your-day",
     *     operationId="widgetsYourDay",
     *     summary="Your Day widget data, display-ready in the user's language, timezone and formats",
     *     tags={"Widgets"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Widget data retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/YourDayWidget")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=426, description="Client version too old", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=429, description="Too many requests", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function yourDay(Request $request): JsonResponse
    {
        return ApiResponse::success(
            new YourDayResource($this->widgetService->yourDay($request->user())),
            'Widget data retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/widgets/actions/todo.complete/{todo}",
     *     operationId="widgetsTodoComplete",
     *     summary="Complete a todo from a widget; completing an already completed todo changes nothing",
     *     tags={"Widgets"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="todo",
     *         in="path",
     *         description="Todo ID",
     *         required=true,
     *
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Todo completed successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="object", nullable=true, example=null)
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Todo not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=426, description="Client version too old", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=429, description="Too many requests", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function completeTodo(Request $request, Todo $todo): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $todo), 403);

        $this->widgetService->completeTodo($request->user(), $todo);

        return ApiResponse::success(null, 'Todo completed successfully.');
    }
}
