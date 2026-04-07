<?php

namespace App\Api\Users\Policies;

use App\Api\Users\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $model): bool
    {
        return $user->id === $model->id || $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, User $model): bool | Response
    {
        if ($user->id === $model->id) {
            return true;
        }

        if ($user->isAdmin()) {
            if ($model->isSuperAdmin() && !$user->isSuperAdmin()) {
                return Response::deny('You cannot modify a super administrator.');
            }

            return true;
        }

        return false;
    }

    public function delete(User $user, User $model): bool | Response
    {
        if ($user->id === $model->id) {
            return Response::deny('You cannot delete your own account.');
        }

        if (!$user->isAdmin()) {
            return false;
        }

        if ($model->isSuperAdmin() && !$user->isSuperAdmin()) {
            return Response::deny('You cannot delete a super administrator.');
        }

        return true;
    }

    public function restore(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    public function forceDelete(User $user, User $model): bool
    {
        return $user->isAdmin();
    }
}
