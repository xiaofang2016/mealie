<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait ResolvesMealieUser
{
    protected function mealieUser(Request $request): object
    {
        /** @var object $user */
        $user = $request->attributes->get('mealieUser');

        return $user;
    }

    protected function unlessCanManage(object $user): ?\Illuminate\Http\JsonResponse
    {
        $admin = (int) ($user->admin ?? 0) === 1 || $user->admin === true;
        $manage = (int) ($user->can_manage ?? 0) === 1 || $user->can_manage === true;
        if ($admin || $manage) {
            return null;
        }

        return response()->json(['detail' => 'User is not allowed to manage this group'], 403);
    }
}
