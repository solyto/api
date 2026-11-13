<?php

namespace App\Api\Calendars\Controllers;

use App\Api\ApiResponse;
use App\Api\Calendars\Models\ExternalCalendarAccount;
use App\Api\Calendars\Requests\ConnectExternalCalendarsRequest;
use App\Api\Calendars\Requests\StoreExternalCalendarAccountRequest;
use App\Api\Calendars\Requests\UpdateExternalCalendarAccountRequest;
use App\Api\Calendars\Resources\CalendarResource;
use App\Api\Calendars\Resources\ExternalCalendarAccountResource;
use App\Api\Calendars\Resources\ExternalCalendarCandidateResource;
use App\Api\Calendars\Services\ExternalCalendarService;
use App\Api\HandlesApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExternalCalendarController
{
    use HandlesApiAuth;

    public function __construct(private readonly ExternalCalendarService $externalCalendarService) {}

    /**
     * @OA\Get(
     *     path="/calendars/external/accounts",
     *     operationId="externalCalendarAccountList",
     *     summary="List the accounts on the user's own CalDAV servers with their connected calendars",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Accounts retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/ExternalCalendarAccount"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function listAccounts(Request $request): JsonResponse
    {
        return ApiResponse::success(
            ExternalCalendarAccountResource::collection($this->externalCalendarService->listAccounts($request->user())),
            'Accounts retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/calendars/external/accounts",
     *     operationId="externalCalendarAccountStore",
     *     summary="Add an account on a CalDAV server (username and app password); stored only if calendars are found there",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"server_url","username","password"},
     *
     *             @OA\Property(property="server_url", type="string", maxLength=2048, example="https://cloud.example.com"),
     *             @OA\Property(property="username", type="string", maxLength=255),
     *             @OA\Property(property="password", type="string", maxLength=1024, description="App password; never returned")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Account added successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/ExternalCalendarAccount")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error, URL not allowed or no calendars found", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function storeAccount(StoreExternalCalendarAccountRequest $request): JsonResponse
    {
        $account = $this->externalCalendarService->createAccount($request->user(), $request->validated());

        return ApiResponse::success(new ExternalCalendarAccountResource($account), 'Account added successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/calendars/external/accounts/{account}",
     *     operationId="externalCalendarAccountUpdate",
     *     summary="Replace the account's app password (and username); checked against the server first",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="account", in="path", required=true, description="Account ID", @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"password"},
     *
     *             @OA\Property(property="username", type="string", maxLength=255),
     *             @OA\Property(property="password", type="string", maxLength=1024)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Account updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/ExternalCalendarAccount")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Account not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error or no calendars found with these credentials", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function updateAccount(UpdateExternalCalendarAccountRequest $request, ExternalCalendarAccount $account): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $account), 403);

        $account = $this->externalCalendarService->updateAccount($account, $request->validated());

        return ApiResponse::success(new ExternalCalendarAccountResource($account), 'Account updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/calendars/external/accounts/{account}",
     *     operationId="externalCalendarAccountDestroy",
     *     summary="Remove the account and its connected calendars from solyto; the server is not changed",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="account", in="path", required=true, description="Account ID", @OA\Schema(type="integer")),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Account removed successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Account not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroyAccount(Request $request, ExternalCalendarAccount $account): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $account), 403);

        $this->externalCalendarService->destroyAccount($request->user(), $account);

        return ApiResponse::success(null, 'Account removed successfully.');
    }

    /**
     * @OA\Get(
     *     path="/calendars/external/accounts/{account}/available",
     *     operationId="externalCalendarAvailableList",
     *     summary="List the calendars on the account's server",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="account", in="path", required=true, description="Account ID", @OA\Schema(type="integer")),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Calendars retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/ExternalCalendarCandidate"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Account not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="No calendars found on the server", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function listAvailable(Request $request, ExternalCalendarAccount $account): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $account), 403);

        return ApiResponse::success(
            ExternalCalendarCandidateResource::collection($this->externalCalendarService->listAvailable($account)),
            'Calendars retrieved successfully.'
        );
    }

    /**
     * @OA\Post(
     *     path="/calendars/external/accounts/{account}/calendars",
     *     operationId="externalCalendarConnect",
     *     summary="Connect calendars from the account's server; they then work like native calendars",
     *     tags={"Calendars"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(name="account", in="path", required=true, description="Account ID", @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"urls"},
     *
     *             @OA\Property(property="urls", type="array", description="url values from the available list", @OA\Items(type="string"))
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Calendars connected successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Calendar"))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Account not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error or a URL the server did not list", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function connectCalendars(ConnectExternalCalendarsRequest $request, ExternalCalendarAccount $account): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $account), 403);

        return ApiResponse::success(
            CalendarResource::collection($this->externalCalendarService->connect($request->user(), $account, $request->validated('urls'))),
            'Calendars connected successfully.',
            201
        );
    }
}
