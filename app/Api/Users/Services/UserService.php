<?php

namespace App\Api\Users\Services;

use App\Api\Users\Models\User;
use App\Api\Users\Services\UserProfileImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function __construct(private readonly UserProfileImageService $profileImageService) {}

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return User::query()->latest()->paginate($perPage);
    }

    public function find(User $user): User
    {
        return $user;
    }

    public function create(array $data): User
    {
        return User::create($data);
    }

    public function update(User $user, array $data): User
    {
        $user->update($data);

        return $user;
    }

    public function destroy(User $user): void
    {
        $user->delete();
    }

    public function changePassword(User $user, string $newPassword): void
    {
        $user->update(['password' => Hash::make($newPassword)]);
        $user->tokens()->delete();
    }

    public function me(User $user): User
    {
        $user->load(['profile', 'settings']);

        return $user;
    }

    public function updateProfileImage(User $user, UploadedFile $file): bool
    {
        $filePath = $this->profileImageService->save($user->id, $file);

        if (!$filePath) {
            return false;
        }

        $user->profile()->update(['profile_image_path' => $filePath]);

        return true;
    }

    public function publicProfile(User $user): User
    {
        return $user;
    }
}
