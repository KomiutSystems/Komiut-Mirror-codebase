<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\UserType;
use App\Models\Scopes\FinancierScope;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Who may hand out roles at all.
 *
 * The permission ceiling compares permission NAMES: a role may be given only
 * when the caller holds every permission in it. That is honest only when the
 * caller's permissions reach as far as the role's will. Two kinds of account
 * hold the names but not the reach:
 *
 *   - limited to the buses they own (an Investor, or a crew-typed account:
 *     ScopesToOwnedVehicles), and
 *   - limited to the buses their bank financed (FinancierScope).
 *
 * Either could otherwise give someone a fleet-wide role -- or create an office
 * account with one -- that reads the whole SACCO they themselves cannot. So
 * neither may give roles. No production account was in that state when this
 * was added (2026-11-25).
 */
trait GrantsRoles
{
    use ScopesToOwnedVehicles;

    /** Why the signed-in caller may not give roles, or null when they may. */
    protected function limitedRoleGranter(): ?string
    {
        $caller = Auth::user();
        if (! $caller instanceof User || $caller->isSuperAdmin()) {
            return null;
        }

        if (FinancierScope::confines($caller)) {
            return 'Your access is limited to the buses your bank financed, so you cannot give anyone roles.';
        }

        if ($this->ownedVehicleIds() !== null) {
            return $caller->type === UserType::Driver
                ? 'Your account is a crew account, limited to its own bus, so it cannot give anyone roles. '
                    .'Ask a platform admin to make it an office account.'
                : 'Your access is limited to your own buses, so you cannot give anyone roles.';
        }

        return null;
    }
}
