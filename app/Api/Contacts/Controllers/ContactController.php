<?php

namespace App\Api\Contacts\Controllers;

use App\Api\ApiResponse;
use App\Api\Contacts\Requests\AddContactPhotoRequest;
use App\Api\Contacts\Requests\GetContactPhotosRequest;
use App\Api\Contacts\Requests\StoreAddressBookRequest;
use App\Api\Contacts\Requests\StoreContactRequest;
use App\Api\Contacts\Requests\UpdateAddressBookRequest;
use App\Api\Contacts\Requests\UpdateContactRequest;
use App\Api\Contacts\Resources\AddressBookResource;
use App\Api\Contacts\Resources\ContactResource;
use App\Api\Contacts\Services\ContactService;
use App\Api\HandlesApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController
{
    use HandlesApiAuth;

    public function __construct(private readonly ContactService $contactService) {}

    /**
     * @OA\Get(
     *     path="/address-books",
     *     operationId="listAddressBooks",
     *     tags={"Contacts"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Address books retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/AddressBook"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function listAddressBooks(Request $request): JsonResponse
    {
        return ApiResponse::success(
            AddressBookResource::collection($this->contactService->listAddressBooks($request->user())),
            'Address books retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/address-books",
     *     operationId="storeAddressBook",
     *     tags={"Contacts"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name"},
     *
     *             @OA\Property(property="name", type="string", maxLength=255, example="My Contacts")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Address Book created successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/AddressBook")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=409, description="Address Book already exists", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function storeAddressBook(StoreAddressBookRequest $request): JsonResponse
    {
        $data = $request->validated();

        $addressBook = $this->contactService->createAddressBook($request->user(), $data);

        return ApiResponse::success(new AddressBookResource($addressBook), 'Address Book created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/address-books/{addressBookId}",
     *     operationId="updateAddressBook",
     *     tags={"Contacts"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="addressBookId",
     *         in="path",
     *         required=true,
     *         description="Address Book ID",
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
     *         description="Address book updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/AddressBook")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Address Book not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function updateAddressBook(UpdateAddressBookRequest $request, int $addressBookId): JsonResponse
    {
        $addressBook = $this->contactService->getAddressBook($request->user(), $addressBookId);

        if ($addressBook === null) {
            return ApiResponse::error('Address Book does not exist', 404);
        }

        $addressBook->color = $request->validated('color');
        $this->contactService->updateAddressBook($request->user(), $addressBook);

        return ApiResponse::success(new AddressBookResource($addressBook), 'Address book updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/address-books/{addressBookId}",
     *     operationId="destroyAddressBook",
     *     tags={"Contacts"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="addressBookId",
     *         in="path",
     *         required=true,
     *         description="Address Book ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Address Book deleted successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Address Book not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroyAddressBook(Request $request, int $addressBookId): JsonResponse
    {
        $addressBook = $this->contactService->getAddressBook($request->user(), $addressBookId);

        if ($addressBook === null) {
            return ApiResponse::error('Address Book does not exist', 404);
        }

        $this->contactService->destroyAddressBook($request->user(), $addressBook);

        return ApiResponse::success(null, 'Address Book deleted successfully.');
    }

    /**
     * @OA\Get(
     *     path="/address-books/contacts",
     *     operationId="listContacts",
     *     tags={"Contacts"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Contacts retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Contact"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function listContacts(Request $request): JsonResponse
    {
        return ApiResponse::success(
            ContactResource::collection($this->contactService->listContacts($request->user())),
            'Contacts retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/address-books/contacts/photos",
     *     operationId="getContactPhotos",
     *     tags={"Contacts"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"contacts"},
     *
     *             @OA\Property(
     *                 property="contacts",
     *                 type="array",
     *                 maxItems=10,
     *
     *                 @OA\Items(
     *
     *                     @OA\Property(property="address_book_id", type="integer", example=1),
     *                     @OA\Property(property="uri", type="string", example="contact123.vcf")
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Contact photos retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="object", @OA\AdditionalProperties(type="string", format="uri"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function getContactPhotos(GetContactPhotosRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->contactService->getContactPhotos($request->user(), $request->validated('contacts'))
        );
    }

    /**
     * @OA\Post(
     *     path="/address-books/{addressBookId}/contacts",
     *     operationId="storeContact",
     *     tags={"Contacts"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="addressBookId",
     *         in="path",
     *         required=true,
     *         description="Address Book ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"address_book_id"},
     *
     *             @OA\Property(property="address_book_id", type="integer", example=1),
     *             @OA\Property(property="full_name", type="string", maxLength=255, nullable=true, example="John Doe"),
     *             @OA\Property(property="first_name", type="string", maxLength=255, nullable=true, example="John"),
     *             @OA\Property(property="last_name", type="string", maxLength=255, nullable=true, example="Doe"),
     *             @OA\Property(property="middle_name", type="string", maxLength=255, nullable=true, example="William"),
     *             @OA\Property(property="prefix", type="string", maxLength=50, nullable=true, example="Mr."),
     *             @OA\Property(property="suffix", type="string", maxLength=50, nullable=true, example="Jr."),
     *             @OA\Property(property="email", type="string", nullable=true, example="[{&quot;value&quot;:&quot;john@example.com&quot;,&quot;type&quot;:&quot;work&quot;}]"),
     *             @OA\Property(property="phone", type="string", nullable=true, example="[{&quot;value&quot;:&quot;555-1234&quot;,&quot;type&quot;:&quot;mobile&quot;}]"),
     *             @OA\Property(property="groups", type="string", nullable=true, example="[&quot;Family&quot;,&quot;Work&quot;]"),
     *             @OA\Property(property="organization", type="string", maxLength=255, nullable=true, example="Acme Corp"),
     *             @OA\Property(property="title", type="string", maxLength=255, nullable=true, example="Developer"),
     *             @OA\Property(property="note", type="string", nullable=true, example="Notes about this contact"),
     *             @OA\Property(property="photo", type="string", nullable=true, example="base64-encoded-image"),
     *             @OA\Property(property="street", type="string", maxLength=255, nullable=true, example="123 Main St"),
     *             @OA\Property(property="city", type="string", maxLength=255, nullable=true, example="New York"),
     *             @OA\Property(property="state", type="string", maxLength=255, nullable=true, example="NY"),
     *             @OA\Property(property="postal_code", type="string", maxLength=20, nullable=true, example="10001"),
     *             @OA\Property(property="country", type="string", maxLength=255, nullable=true, example="USA")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Contact created successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Contact")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Address Book not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function storeContact(StoreContactRequest $request, int $addressBookId): JsonResponse
    {
        $addressBook = $this->contactService->getAddressBook($request->user(), $addressBookId);

        if ($addressBook === null) {
            return ApiResponse::error('Address Book does not exist', 404);
        }

        $contact = $this->contactService->createContact($request->user(), $addressBook, $request->validated());

        return ApiResponse::success(new ContactResource($contact), 'Contact created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/address-books/{addressBookId}/contacts/{contactUri}",
     *     operationId="updateContact",
     *     tags={"Contacts"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="addressBookId",
     *         in="path",
     *         required=true,
     *         description="Address Book ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Parameter(
     *         name="contactUri",
     *         in="path",
     *         required=true,
     *         description="Contact URI",
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"address_book_id"},
     *
     *             @OA\Property(property="address_book_id", type="integer", example=1),
     *             @OA\Property(property="full_name", type="string", maxLength=255, nullable=true, example="John Doe"),
     *             @OA\Property(property="first_name", type="string", maxLength=255, nullable=true, example="John"),
     *             @OA\Property(property="last_name", type="string", maxLength=255, nullable=true, example="Doe"),
     *             @OA\Property(property="middle_name", type="string", maxLength=255, nullable=true, example="William"),
     *             @OA\Property(property="prefix", type="string", maxLength=50, nullable=true, example="Mr."),
     *             @OA\Property(property="suffix", type="string", maxLength=50, nullable=true, example="Jr."),
     *             @OA\Property(property="email", type="string", nullable=true, example="[{&quot;value&quot;:&quot;john@example.com&quot;,&quot;type&quot;:&quot;work&quot;}]"),
     *             @OA\Property(property="phone", type="string", nullable=true, example="[{&quot;value&quot;:&quot;555-1234&quot;,&quot;type&quot;:&quot;mobile&quot;}]"),
     *             @OA\Property(property="groups", type="string", nullable=true, example="[&quot;Family&quot;,&quot;Work&quot;]"),
     *             @OA\Property(property="organization", type="string", maxLength=255, nullable=true, example="Acme Corp"),
     *             @OA\Property(property="title", type="string", maxLength=255, nullable=true, example="Developer"),
     *             @OA\Property(property="note", type="string", nullable=true, example="Notes about this contact"),
     *             @OA\Property(property="photo", type="string", nullable=true, example="base64-encoded-image"),
     *             @OA\Property(property="street", type="string", maxLength=255, nullable=true, example="123 Main St"),
     *             @OA\Property(property="city", type="string", maxLength=255, nullable=true, example="New York"),
     *             @OA\Property(property="state", type="string", maxLength=255, nullable=true, example="NY"),
     *             @OA\Property(property="postal_code", type="string", maxLength=20, nullable=true, example="10001"),
     *             @OA\Property(property="country", type="string", maxLength=255, nullable=true, example="USA")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Contact updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Contact")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Address Book or Contact not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function updateContact(UpdateContactRequest $request, int $addressBookId, string $contactUri): JsonResponse
    {
        $addressBook = $this->contactService->getAddressBook($request->user(), $addressBookId);

        if ($addressBook === null) {
            return ApiResponse::error('Address Book does not exist', 404);
        }

        $contact = $this->contactService->getContact($addressBook, $contactUri);

        if ($contact === null) {
            return ApiResponse::error('Contact does not exist', 404);
        }

        $contact = $this->contactService->updateContact($request->user(), $addressBook, $contact, $request->validated());

        if ($contact === null) {
            return ApiResponse::error('Error updating contact', 500);
        }

        return ApiResponse::success(new ContactResource($contact), 'Contact updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/address-books/{addressBookId}/contacts/{contactUri}",
     *     operationId="destroyContact",
     *     tags={"Contacts"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="addressBookId",
     *         in="path",
     *         required=true,
     *         description="Address Book ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Parameter(
     *         name="contactUri",
     *         in="path",
     *         required=true,
     *         description="Contact URI",
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Contact deleted successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Address Book or Contact not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroyContact(Request $request, int $addressBookId, string $contactUri): JsonResponse
    {
        $addressBook = $this->contactService->getAddressBook($request->user(), $addressBookId);

        if ($addressBook === null) {
            return ApiResponse::error('Address Book does not exist', 404);
        }

        $contact = $this->contactService->getContact($addressBook, $contactUri);

        if ($contact === null) {
            return ApiResponse::error('Contact does not exist', 404);
        }

        if (! $this->contactService->destroyContact($request->user(), $addressBook, $contact)) {
            return ApiResponse::error('Error deleting contact', 500);
        }

        return ApiResponse::success(null, 'Contact deleted successfully.');
    }

    /**
     * @OA\Post(
     *     path="/address-books/{addressBookId}/contacts/{contactUri}/photo",
     *     operationId="updateContactPhoto",
     *     tags={"Contacts"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="addressBookId",
     *         in="path",
     *         required=true,
     *         description="Address Book ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Parameter(
     *         name="contactUri",
     *         in="path",
     *         required=true,
     *         description="Contact URI",
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *
     *             @OA\Schema(
     *                 required={"photo"},
     *
     *                 @OA\Property(property="photo", type="string", format="binary")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Contact photo updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Contact")
     *         )
     *     ),
     *
     *     @OA\Response(response=400, description="No photo uploaded", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Address Book or Contact not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function updateContactPhoto(AddContactPhotoRequest $request, int $addressBookId, string $contactUri): JsonResponse
    {
        $addressBook = $this->contactService->getAddressBook($request->user(), $addressBookId);

        if ($addressBook === null) {
            return ApiResponse::error('Address Book does not exist', 404);
        }

        $contact = $this->contactService->getContact($addressBook, $contactUri);

        if ($contact === null) {
            return ApiResponse::error('Contact does not exist', 404);
        }

        if (! $request->hasFile('photo')) {
            return ApiResponse::error('No photo uploaded', 400);
        }

        $contact = $this->contactService->updateContactPhoto($request->user(), $addressBook, $contact, $request->file('photo'));

        if ($contact === null) {
            return ApiResponse::error('Error updating contact', 500);
        }

        return ApiResponse::success(new ContactResource($contact), 'Contact photo updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/address-books/{addressBookId}/contacts/{contactUri}/photo",
     *     operationId="removeContactPhoto",
     *     tags={"Contacts"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="addressBookId",
     *         in="path",
     *         required=true,
     *         description="Address Book ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Parameter(
     *         name="contactUri",
     *         in="path",
     *         required=true,
     *         description="Contact URI",
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Contact updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Contact")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Address Book or Contact not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function removeContactPhoto(Request $request, int $addressBookId, string $contactUri): JsonResponse
    {
        $addressBook = $this->contactService->getAddressBook($request->user(), $addressBookId);

        if ($addressBook === null) {
            return ApiResponse::error('Address Book does not exist', 404);
        }

        $contact = $this->contactService->getContact($addressBook, $contactUri);

        if ($contact === null) {
            return ApiResponse::error('Contact does not exist', 404);
        }

        $contact = $this->contactService->removeContactPhoto($request->user(), $addressBook, $contact);

        if ($contact === null) {
            return ApiResponse::error('Error updating contact', 500);
        }

        return ApiResponse::success(new ContactResource($contact), 'Contact updated successfully.');
    }
}
