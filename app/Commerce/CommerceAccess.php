<?php

namespace App\Commerce;

use App\Models\User;
use App\Support\ImpersonationManager;

final class CommerceAccess
{
    public static function allows(?User $user, string $permission): bool
    {
        if (! $user?->is_active || ! $user->hasPermissionTo($permission)) {
            return false;
        }
        $session = app(ImpersonationManager::class);

        return ! $session->active() || ($session->actor()?->is_active && $session->actor()->hasPermissionTo($permission));
    }

    public static function authorize(string $permission): void
    {
        abort_unless(self::allows(auth()->user(), $permission), 403);
    }
}
