<?php

namespace App\Api\Users\Controllers;

use App\Api\ApiResponse;
use App\Api\Users\Enums\UserGrowthPeriod;
use App\Api\Users\Models\User;
use App\Api\Users\Requests\ChangePasswordRequest;
use App\Api\Users\Requests\ListUsersRequest;
use App\Api\Users\Requests\RequestAccountDeletionRequest;
use App\Api\Users\Requests\UpdateProfileImageRequest;
use App\Api\Users\Requests\UpdateUserRequest;
use App\Api\Users\Requests\UserGrowthRequest;
use App\Api\Users\Resources\UserPublicProfileResource;
use App\Api\Users\Resources\UserResource;
use App\Api\Users\Services\UserService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController
{
    use AuthorizesRequests;

    // Released frontends send no per_page and expect the whole list on one page.
    private const int LEGACY_PAGE_SIZE = 1000;

    public function __construct(private readonly UserService $userService) {}

    /**
     * @OA\Get(
     *     path="/users",
     *     operationId="userIndex",
     *     summary="List users, newest first (admin only)",
     *     tags={"Users"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number",
     *         required=false,
     *
     *         @OA\Schema(type="integer", minimum=1, default=1)
     *     ),
     *
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Users per page; when omitted, 1000 (the page size released frontends rely on)",
     *         required=false,
     *
     *         @OA\Schema(type="integer", minimum=1, maximum=100)
     *     ),
     *
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Case-insensitive substring of the name or email",
     *         required=false,
     *
     *         @OA\Schema(type="string", maxLength=255)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Users retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/User")),
     *             @OA\Property(
     *                 property="meta",
     *                 type="object",
     *
     *                 @OA\Property(property="pagination", ref="#/components/schemas/PaginationMeta")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function index(ListUsersRequest $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $users = $this->userService->list(
            $request->integer('per_page', self::LEGACY_PAGE_SIZE),
            $request->validated('search'),
        );

        return ApiResponse::success(UserResource::collection($users), 'Users retrieved successfully.');
    }

    /**
     * @OA\Get(
     *     path="/users/growth",
     *     operationId="userGrowth",
     *     summary="Cumulative user count at the end of each UTC period (admin only)",
     *     description="year and month list only periods with signups; week covers the last 52 ISO weeks and day the last 30 days, gap-filled. Ascending.",
     *     tags={"Users"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="group_by",
     *         in="query",
     *         required=true,
     *
     *         @OA\Schema(type="string", enum={"year","month","week","day"})
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="User growth retrieved successfully",
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
     *                     @OA\Property(property="period", type="string", example="2026-W03", description="2026, 2026-01, 2026-W03 or 2026-01-05"),
     *                     @OA\Property(property="total", type="integer")
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function growth(UserGrowthRequest $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        return ApiResponse::success(
            $this->userService->growth($request->enum('group_by', UserGrowthPeriod::class)),
            'User growth retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/users/me",
     *     operationId="userShow",
     *     summary="Get user details",
     *     tags={"Users"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="User retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/User"),
     *             @OA\Property(
     *                 property="meta",
     *                 type="object",
     *
     *                 @OA\Property(property="account_deletion_requires_email", type="boolean", description="Whether POST /users/me/deletion-request mails a confirmation link (true) or deletes right away (false)")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="User not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function me(Request $request): JsonResponse
    {
        $user = $this->userService->me($request->user());

        return ApiResponse::success(new UserResource($user), 'User retrieved successfully.', 200, [
            'account_deletion_requires_email' => $this->userService->deletionRequiresEmail(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $this->userService->update($user, $request->validated());

        return ApiResponse::success(new UserResource($user), 'User updated successfully.');
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->userService->changePassword($request->user(), $request->validated('new_password'));

        return ApiResponse::success(null, 'Password changed successfully.');
    }

    /**
     * @OA\Post(
     *     path="/users/me/deletion-request",
     *     operationId="userRequestDeletion",
     *     summary="Delete the own account: mails a confirmation link valid for 60 minutes, or deletes right away when email confirmation is off or mail is not configured",
     *     tags={"Users"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"password"},
     *
     *             @OA\Property(property="password", type="string", description="Current password"),
     *             @OA\Property(property="platform", type="string", enum={"web","mobile","desktop"}, nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Confirmation link sent (status email_sent) or account deleted (status deleted)",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *
     *                 @OA\Property(property="status", type="string", enum={"email_sent","deleted"})
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Super administrators cannot delete their own account", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse")),
     *     @OA\Response(response=429, description="Too many requests - errors: [too_many_requests], meta.retry_after in seconds", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function requestDeletion(RequestAccountDeletionRequest $request): JsonResponse
    {
        abort_if($request->user()->isSuperAdmin(), 403, 'Super administrators cannot delete their own account.');

        $status = $this->userService->requestDeletion($request->user(), $request->validated('platform') ?? 'web');

        return ApiResponse::success(['status' => $status], match ($status) {
            'email_sent' => 'A confirmation link has been sent to your email address.',
            'deleted' => 'Your account has been deleted.',
        });
    }

    /**
     * @OA\Post(
     *     path="/users/me/profile-image",
     *     operationId="userUpdateProfileImage",
     *     summary="Update user profile image",
     *     tags={"Users"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *
     *             @OA\Schema(
     *                 required={"profile_image"},
     *
     *                 @OA\Property(
     *                     property="profile_image",
     *                     type="string",
     *                     format="binary",
     *                     description="Profile image file (max 2MB)"
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Profile image updated successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function updateProfileImage(UpdateProfileImageRequest $request): JsonResponse
    {
        if (! $request->hasFile('profile_image')) {
            return ApiResponse::error();
        }

        $success = $this->userService->updateProfileImage($request->user(), $request->file('profile_image'));

        if (! $success) {
            return ApiResponse::error();
        }

        return ApiResponse::success(null, 'Profile image updated successfully.');
    }

    /**
     * @OA\Get(
     *     path="/users/{user}/public-profile",
     *     operationId="userPublicProfile",
     *     summary="Get user public profile",
     *     tags={"Users"},
     *
     *     @OA\Parameter(
     *         name="user",
     *         in="path",
     *         description="User ID",
     *         required=true,
     *
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Public profile retrieved successfully",
     *
     *         @OA\JsonContent(
     *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
     *
     *             @OA\Property(property="data", ref="#/components/schemas/UserPublicProfile")
     *         )
     *     ),
     *
     *     @OA\Response(response=404, description="User not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function publicProfile(Request $request, User $user): JsonResponse
    {
        return ApiResponse::success(new UserPublicProfileResource($user));
    }
}
