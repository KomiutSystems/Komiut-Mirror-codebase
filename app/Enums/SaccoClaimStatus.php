<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How far a SACCO has travelled from "a name on a list" to "an account with a login".
 *
 *  - Directory: reference data only — imported from a SASRA/NTSA register or
 *    entered by an admin. There is no user, no password, nothing to log into.
 *    Drivers attach themselves to these entries during onboarding, which is the
 *    whole point: they are signed up on the ground long before their SACCO is.
 *  - PendingReview: a driver typed a SACCO that wasn't listed, so the entry
 *    exists on one person's word. Searchable, but not yet verified by us.
 *  - Claimed: the SACCO has registered and owns a dashboard login.
 *
 * The backing value is what is stored on `saccos.claim_status`.
 */
enum SaccoClaimStatus: string
{
    case Directory = 'directory';
    case PendingReview = 'pending_review';
    case Claimed = 'claimed';

    /** Only a claimed SACCO has users; the other two are reference data. */
    public function hasDashboardAccess(): bool
    {
        return $this === self::Claimed;
    }
}
