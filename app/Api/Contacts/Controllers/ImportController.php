<?php

namespace App\Api\Contacts\Controllers;

use App\Api\ApiResponse;
use App\Api\Contacts\Jobs\ImportContacts;
use App\Api\Contacts\Jobs\ImportContactsFile;
use App\Api\Contacts\Requests\ImportContactsFileRequest;
use App\Api\Contacts\Requests\SelectImportAddressBooksRequest;
use App\Api\Contacts\Requests\StartImportRequest;
use App\Api\Contacts\Resources\ImportStateResource;
use App\Api\Contacts\Services\ContactService;
use App\Api\HandlesApiAuth;
use App\Dav\Services\DavService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportController
{
    use HandlesApiAuth;

    public function __construct(
        private readonly DavService $dav,
        private readonly ContactService $contactService,
    ) {}

    /**
     * @OA\Post(
     *     path="/address-books/import",
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
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Could not grab address books", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=409, description="A contacts import is already running", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function startImport(StartImportRequest $request): JsonResponse
    {
        $validatedData = $request->validated();
        $this->dav->import()->addressBooks()->start($request->user(), $validatedData['url'], $validatedData['username'], $validatedData['secret']);

        return ApiResponse::success([], 'Import started successfully.');
    }

    /**
     * @OA\Post(
     *     path="/address-books/import/select",
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
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Import state not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=409, description="A contacts import is already running", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
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

        ImportContacts::dispatch($request->user()->id, $dto->jobId);

        return ApiResponse::success([], 'Address books selected successfully.');
    }

    /**
     * @OA\Post(
     *     path="/address-books/import/file",
     *     operationId="startContactsFileImport",
     *     tags={"Contacts"},
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
     *                 @OA\Property(property="file", type="string", format="binary", description="vCard file (.vcf), at most 25 MB and 10000 contacts"),
     *                 @OA\Property(property="address_book_id", type="integer", nullable=true, description="Own address book to import into; without it a new address book is created"),
     *                 @OA\Property(property="name", type="string", maxLength=255, nullable=true, description="New address book only; defaults to the file name"),
     *                 @OA\Property(property="color", type="string", maxLength=255, nullable=true, description="New address book only", example="#0088CC")
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
     *             @OA\Property(property="data", ref="#/components/schemas/ContactsImportState")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Address book does not exist", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=409, description="A contacts import is already running", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error (ValidationErrorResponse), or the file has no contacts or more than 10000 (ErrorResponse)",
     *
     *         @OA\JsonContent(oneOf={@OA\Schema(ref="#/components/schemas/ValidationErrorResponse"), @OA\Schema(ref="#/components/schemas/ErrorResponse")})
     *     ),
     *
     *     @OA\Response(response=429, description="Too many requests", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function importFile(ImportContactsFileRequest $request): JsonResponse
    {
        $addressBookId = $request->validated('address_book_id');
        $addressBook = $addressBookId !== null ? $this->contactService->getAddressBookOrFail($request->user(), (int) $addressBookId) : null;

        $dto = $this->dav->import()->addressBooks()->startFile(
            $request->user(),
            $request->file('file'),
            $addressBook,
            $request->validated('name'),
            $request->validated('color'),
        );

        ImportContactsFile::dispatch($request->user()->id, $dto->jobId);

        return ApiResponse::success(new ImportStateResource($dto), 'Import started successfully.');
    }

    /**
     * @OA\Get(
     *     path="/address-books/import/state",
     *     operationId="getContactsImportState",
     *     tags={"Contacts"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Import state retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
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
