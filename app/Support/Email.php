<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One canonical form for an email address.
 *
 * The sibling of Phone, and it exists for the same reason: an identifier the
 * person types by hand has to match the row we stored, and "the same address"
 * has more than one spelling.
 *
 * WHAT BROKE. `Auth::attempt(['email' => …])` compares with `=`. On MySQL that
 * was case-INSENSITIVE by default collation, so the legacy stack matched
 * `Henry@gmail.com` against a stored `henry@gmail.com` and nobody ever noticed.
 * PostgreSQL's `=` on text is case-SENSITIVE, so the same sign-in stopped
 * working the moment the platform moved to Frankfurt — with a wrong-password
 * error, which sent people to reset a password that was never wrong.
 *
 * That is not a small set: 224 of 6,805 accounts are stored with an uppercase
 * letter in the address, including a superadmin and a SACCO admin, and every
 * account stored in lowercase is unreachable to anyone who capitalises the first
 * letter — which phone keyboards do on their own.
 *
 * The domain half of an address is case-insensitive by RFC 5321, and the local
 * half is technically case-SENSITIVE — but no mail provider anyone here uses
 * treats it that way, and the legacy system already accepted any case for a
 * decade. Matching case-insensitively is therefore what our own data means, and
 * it is verified safe: zero addresses in production collide when lowercased.
 *
 * Lookups go through User::byEmail(), which uses normalise() on the input and
 * LOWER() on the column, so it finds the row whatever case either side is in.
 */
final class Email
{
    /**
     * The comparable form: trimmed and lowercased, or null when there is
     * nothing to compare.
     *
     * Returns null rather than '' so a caller cannot accidentally match the
     * rows of every account that has no email recorded.
     */
    public static function normalise(?string $raw): ?string
    {
        $trimmed = trim((string) $raw);

        return $trimmed === '' ? null : mb_strtolower($trimmed);
    }
}
