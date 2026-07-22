<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kind of account, set at creation. This is the account's identity bucket —
 * distinct from spatie roles, which only ever apply to `admin`.
 *
 *  - Passenger / Driver  → mobile apps, fixed capabilities from the type itself
 *  - Admin (SACCO admin) → web dashboard, capabilities assigned via roles
 *  - Superadmin          → platform-level, sees across all SACCOs
 *
 * Social login (Google/Apple) may only ever produce a Passenger.
 */
enum UserType: string
{
    case Passenger = 'passenger';
    case Driver = 'driver';
    case Admin = 'admin';
    case Superadmin = 'superadmin';
}
