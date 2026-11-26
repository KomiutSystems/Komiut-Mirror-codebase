<?php

declare(strict_types=1);

namespace App\Support;

use App\Auth\Roles;
use App\Models\User;
use Illuminate\Support\Facades\Context;

/**
 * Who may work in the platform console (/super), and where their view of
 * every tenant holds.
 *
 * A Super Admin sits above every boundary everywhere, as before. Safetek
 * Staff see across SACCOs and brands ONLY inside a console request -- the
 * `super` middleware marks the request -- where every route checks a
 * permission and the staff role holds only its read-only slices. Anywhere
 * else (the SACCO dashboard, the apps) a staff account is an admin of no
 * SACCO, which the tenant scopes fail closed on, so an ungated endpoint can
 * never become a window onto every SACCO's data.
 */
final class PlatformConsole
{
    /** Hidden context key the `super` middleware sets for a staff request. */
    private const KEY = 'platform_console_staff';

    public static function isStaff(?User $user): bool
    {
        return $user instanceof User && (bool) $user->status && $user->hasRole(Roles::SAFETEK_STAFF);
    }

    /** May this account open the console at all? (Each route still checks a permission.) */
    public static function admits(?User $user): bool
    {
        return $user instanceof User && ($user->isSuperAdmin() || self::isStaff($user));
    }

    /** Mark this request as a staff member's console request. */
    public static function enterAsStaff(): void
    {
        Context::addHidden(self::KEY, true);
    }

    /** Does $user see every tenant on this request? */
    public static function seesAllTenants(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $user->isSuperAdmin()
            || (Context::getHidden(self::KEY) === true && self::isStaff($user));
    }

    /** A staff console request: sees every tenant, but is not a Super Admin. */
    public static function staffRequest(?User $user): bool
    {
        return $user instanceof User && ! $user->isSuperAdmin() && self::seesAllTenants($user);
    }
}
