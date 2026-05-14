<?php

namespace App\Api\Contacts\Controllers;

use App\Api\ApiResponse;
use App\Api\Contacts\Jobs\ImportContacts;
use App\Api\Contacts\Requests\SelectImportAddressBooksRequest;
use App\Api\Contacts\Requests\StartImportRequest;
use App\Api\Contacts\Resources\ImportStateResource;
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
     *     path="/api/contacts/import/start",
     *     operationId="startContactsImport",
     *     tags={"Contacts"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"url", "username", "secret"},
     *
     *             @OA\Property(property="url", type="string", format="uri", example="https://carddav.example.com/contacts/"),
     *             @OA\Property(property="username", type="string", example="user@example.com"),
     *             @OA\Property(property="secret", type="string", example="app-password")
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
     *     @OA\Response(response=404, description="Could not grab address books", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function startImport(StartImportRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validated();
            $this->dav->import()->addressBooks()->start($request->user(), $validatedData['url'], $validatedData['username'], $validatedData['secret']);
        } catch (ImportException $e) {
            report($e);
            return ApiResponse::error('Could not grab address books', 404);
        }

        return ApiResponse::success([], 'Import started successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/contacts/import/select",
     *     operationId="selectAddressBooksForImport",
     *     tags={"Contacts"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"address_books"},
     *
     *             @OA\Property(property="address_books", type="array", @OA\Items(type="string"), example={"/contacts/default", "/contacts/work"})
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Address books selected successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Address books selected successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Import state not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function selectAddressBooks(SelectImportAddressBooksRequest $request): JsonResponse
    {
        $dto = $this->dav->import()->addressBooks()->getState($request->user()->id);

        if (! $dto) {
            return ApiResponse::error('Import state not found', 404);
        }

        $this->dav->import()->addressBooks()->selectAddressBooks($request->user(), $dto, $request->validated()['address_books']);

        ImportContacts::dispatch($request->user());

        return ApiResponse::success([], 'Address books selected successfully.');
    }

    /**
     * @OA\Get(
     *     path="/api/contacts/import/state",
     *     operationId="getContactsImportState",
     *     tags={"Contacts"},
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
     *             @OA\Property(property="data", ref="#/components/schemas/ContactsImportState")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Import state not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function getState(Request $request): JsonResponse
    {
        $dto = $this->dav->import()->addressBooks()->getState($request->user()->id);

        if (! $dto) {
            return ApiResponse::error('Import state not found', 404);
        }

        return ApiResponse::success(
            new ImportStateResource($dto),
            'Import state retrieved successfully.'
        );
    }
}
