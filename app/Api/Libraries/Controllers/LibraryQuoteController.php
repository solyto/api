<?php

namespace App\Api\Libraries\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Libraries\Models\LibraryQuote;
use App\Api\Libraries\Requests\Quotes\StoreLibraryQuoteRequest;
use App\Api\Libraries\Requests\Quotes\UpdateLibraryQuoteRequest;
use App\Api\Libraries\Resources\LibraryQuoteResource;
use App\Api\Libraries\Services\LibraryQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibraryQuoteController
{
    use HandlesApiAuth;

    public function __construct(private readonly LibraryQuoteService $libraryQuoteService) {}

    /**
     * @OA\Get(
     *     path="/libraries/quotes",
     *     operationId="listLibraryQuotes",
     *     tags={"Libraries - Quotes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Quotes retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LibraryQuote"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            LibraryQuoteResource::collection($this->libraryQuoteService->list($request->user())),
            'Quotes retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/libraries/quotes/{quote}",
     *     operationId="showLibraryQuote",
     *     tags={"Libraries - Quotes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="quote",
     *         in="path",
     *         required=true,
     *         description="Quote ID",
     *
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Quote retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryQuote")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Quote not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, LibraryQuote $quote): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $quote), 403);

        $quote = $this->libraryQuoteService->find($quote);

        return ApiResponse::success(new LibraryQuoteResource($quote), 'Quote retrieved successfully.');
    }

    /**
     * @OA\Get(
     *     path="/libraries/quotes/random",
     *     operationId="randomLibraryQuote",
     *     tags={"Libraries - Quotes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Random Quote entry retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryQuote")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="No quote found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function random(Request $request): JsonResponse
    {
        $quote = $this->libraryQuoteService->random($request->user());

        if (! $quote) {
            return ApiResponse::error('No quote found.', 404);
        }

        return ApiResponse::success(new LibraryQuoteResource($quote), 'Random Quote entry retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/libraries/quotes",
     *     operationId="storeLibraryQuote",
     *     tags={"Libraries - Quotes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"quote"},
     *
     *             @OA\Property(property="summary", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="author", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="quote", type="string"),
     *             @OA\Property(property="source", type="string", maxLength=500, nullable=true),
     *             @OA\Property(property="tags", type="array", @OA\Items(type="string"), nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Quote created successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryQuote")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function store(StoreLibraryQuoteRequest $request): JsonResponse
    {
        $quote = $this->libraryQuoteService->create($request->user(), $request->validated());

        return ApiResponse::success(new LibraryQuoteResource($quote), 'Quote created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/libraries/quotes/{quote}",
     *     operationId="updateLibraryQuote",
     *     tags={"Libraries - Quotes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="quote",
     *         in="path",
     *         required=true,
     *         description="Quote ID",
     *
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             @OA\Property(property="summary", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="author", type="string", maxLength=255, nullable=true),
     *             @OA\Property(property="quote", type="string"),
     *             @OA\Property(property="source", type="string", maxLength=500, nullable=true),
     *             @OA\Property(property="tags", type="array", @OA\Items(type="string"), nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Quote updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/LibraryQuote")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(UpdateLibraryQuoteRequest $request, LibraryQuote $quote): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $quote), 403);

        $quote = $this->libraryQuoteService->update($quote, $request->validated());

        return ApiResponse::success(new LibraryQuoteResource($quote), 'Quote updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/libraries/quotes/{quote}",
     *     operationId="destroyLibraryQuote",
     *     tags={"Libraries - Quotes"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="quote",
     *         in="path",
     *         required=true,
     *         description="Quote ID",
     *
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Quote deleted successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, LibraryQuote $quote): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $quote), 403);

        $this->libraryQuoteService->destroy($quote);

        return ApiResponse::success(null, 'Quote deleted successfully.');
    }
}
