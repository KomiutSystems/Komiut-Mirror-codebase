<?php

declare(strict_types=1);

namespace App\Services\Driver;

use RuntimeException;

/**
 * A phone number already belongs to an account this flow may not rewrite.
 *
 * driver/onboard is PUBLIC — an agent stands at a stage with a driver who has
 * no account yet, so there is nothing to authenticate against. Its one write to
 * an EXISTING account was therefore reachable by anyone who knew a phone number:
 * post it with any SACCO name and that account was moved into that SACCO,
 * reactivated if it had been switched off, and promoted to Driver.
 *
 * The phone is the identity here (`users.phone` is unique), and a phone number
 * is not a secret. So an existing account is only ever adopted when there is
 * nothing to take — no SACCO yet, or the same SACCO the agent named — and every
 * other case stops here.
 *
 * A 409 rather than a 422: the request is well-formed and the agent did nothing
 * wrong. It is the state on our side that conflicts, and the message says who
 * can resolve it.
 */
final class PhoneNotOnboardable extends RuntimeException
{
    /** The account belongs to another SACCO. Moving it is a dashboard action. */
    public static function belongsToAnotherSacco(): self
    {
        return new self(
            'This phone number is already registered to a driver at another SACCO. '
            .'That SACCO can release them from the Crew screen, and then this sign-up will go through.'
        );
    }

    /**
     * The account is staff — an admin, a conductor, a queue supervisor. Street
     * onboarding has no business rewriting one, and quietly reassigning a SACCO
     * admin would take them out of their own SACCO.
     */
    public static function isNotADriverAccount(): self
    {
        return new self(
            'This phone number is already registered to a staff account. '
            .'Add the driver from the SACCO dashboard instead.'
        );
    }

    /**
     * The account was switched off. Re-onboarding used to turn it back on, which
     * made a suspension reversible by anyone who knew the number. Whoever
     * suspended it is the one who can lift it.
     */
    public static function isDeactivated(): self
    {
        return new self(
            'This phone number belongs to a deactivated account. '
            .'The SACCO must re-activate it from the dashboard before the driver can be signed up again.'
        );
    }
}
