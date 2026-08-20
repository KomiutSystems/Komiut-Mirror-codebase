<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One canonical form for a Kenyan mobile number.
 *
 * Every entry point took the number as exact `digits:10` — so a passenger who
 * typed the international form (`+254712345678` or `254712345678`) or dropped the
 * leading zero (`712345678`) could neither register, log in, reset a password,
 * nor pay: the value failed `digits:10` or never matched the stored row. All of
 * those are the SAME number, and this collapses them to one.
 *
 * Canonical stored form is the local `0XXXXXXXXX` (10 digits) — what the app has
 * always written, so nothing needs migrating. For M-Pesa/Daraja, `msisdn()`
 * gives the `2547XXXXXXXX` form the API wants.
 *
 * Kenyan mobiles are Safaricom/Airtel/Telkom: local `07XXXXXXXX` or `01XXXXXXXX`.
 */
final class Phone
{
    /**
     * Collapse any accepted form to canonical local `0XXXXXXXXX`, or null if it
     * is not a valid Kenyan mobile.
     *
     *   +254712345678 / 254712345678 / 0712345678 / 712345678  ->  0712345678
     */
    public static function normalise(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        // Strip everything but digits (drops +, spaces, dashes, parentheses).
        $d = preg_replace('/\D+/', '', $raw) ?? '';

        // 254XXXXXXXXX (12) -> 0XXXXXXXXX
        if (strlen($d) === 12 && str_starts_with($d, '254')) {
            $d = '0'.substr($d, 3);
        } elseif (strlen($d) === 9) {
            // Missing leading zero: 7XXXXXXXX / 1XXXXXXXX -> 0XXXXXXXXX
            $d = '0'.$d;
        }

        // Must now be a 10-digit local mobile: 07XXXXXXXX or 01XXXXXXXX.
        return preg_match('/^0[17]\d{8}$/', $d) === 1 ? $d : null;
    }

    /** True when the input is a recognisable Kenyan mobile in any accepted form. */
    public static function isValid(?string $raw): bool
    {
        return self::normalise($raw) !== null;
    }

    /**
     * The Daraja/M-Pesa form, `2547XXXXXXXX`, or null if invalid.
     */
    public static function msisdn(?string $raw): ?string
    {
        $local = self::normalise($raw);

        return $local === null ? null : '254'.substr($local, 1);
    }

    /**
     * The set of stored forms a lookup should match, so a login/reset finds the
     * user regardless of whether their row was written as local or 254. Returns
     * [canonical-local, msisdn] (or [] if invalid).
     *
     * @return array<int, string>
     */
    public static function lookupForms(?string $raw): array
    {
        $local = self::normalise($raw);

        return $local === null ? [] : [$local, '254'.substr($local, 1)];
    }
}
