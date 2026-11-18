<?php

namespace App\Mcp\Tools;

use App\Api\Users\Models\User;
use Illuminate\Auth\AuthenticationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

abstract class SolytoTool extends Tool
{
    protected function user(Request $request): User
    {
        $user = $request->user();

        if (!$user instanceof User) {
            throw new AuthenticationException();
        }

        return $user;
    }
}
