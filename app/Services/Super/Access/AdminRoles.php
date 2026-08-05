<?php

declare(strict_types=1);

namespace App\Services\Super\Access;

use App\Auth\Roles;
use App\Enums\UserType;
use App\Models\User;

/**
 * The single definition of "an admin account" for the access domain's alerting.
 * Used by the login-burst, privileged-password-reset and zero-permission-login
 * detectors so all three key off the same list instead of drifting apart.
 *
 * An account is admin if its type is a dashboard/platform type OR it carries any
 * management-tier spatie role.
 */
final class AdminRoles
{
    /** @var array<int, string> */
    public const ROLES = [
        Roles::SUPER_ADMIN,
        Roles::SACCO_ADMIN,
        Roles::FLEET_MANAGER,
        Roles::OPERATIONS_MANAGER,
        Roles::FINANCE,
    ];

    public static function holdsAdminRole(User $user): bool
    {
        if (in_array($user->type, [UserType::Admin, UserType::Superadmin], true)) {
            return true;
        }

        return $user->hasAnyRole(self::ROLES);
    }
}
