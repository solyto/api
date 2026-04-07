<?php

namespace App\Api;

use Illuminate\Http\Request;

trait HandlesApiAuth
{
    public function isResourceOwner(Request $request, $resource): bool
    {
        return $resource->user_id === $request->user()->id;
    }

    public function isAdmin(Request $request): bool
    {
        return $request->user()->isAdmin() || $request->user()->isSuperAdmin();
    }

    public function isSuperAdmin(Request $request): bool
    {
        return $request->user()->isSuperAdmin();
    }
}
