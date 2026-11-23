<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\Log;

/**
 * Why a sign-in was refused, written down for us -- never shown to the caller.
 *
 * The crew sign-in answers every failure the same way on purpose: it is public,
 * and it must not confirm who drives here. But that left us blind too. On
 * 2026-11-22, 66 of 119 crew sign-ins failed -- one phone 18 times in a row --
 * and nothing anywhere said whether the phone was unknown, the plate was
 * wrong, or the bus belonged to another SACCO. Each of those has a different
 * fix (register the driver, correct the plate, move the bus), and support
 * could act on none of them.
 *
 * WHAT IS RECORDED, AND WHAT IS NOT. The reason, the account and vehicle ids
 * when they resolved, the caller's address, and a keyed hash of the phone so
 * repeated attempts from one person can be grouped. Never the phone itself
 * (DriverLoginBurst's rule: the phone is half of the credential), and never
 * what was typed into the plate or password field -- someone always types
 * their password into the wrong box.
 */
final class LoginRefusals
{
    public const UNKNOWN_PHONE = 'unknown_phone';

    public const NOT_A_DRIVER = 'not_a_driver';

    public const INACTIVE_ACCOUNT = 'inactive_account';

    public const UNKNOWN_PLATE = 'unknown_plate';

    public const OTHER_SACCO = 'other_sacco';

    public const WRONG_PASSWORD = 'wrong_password';

    public const NO_ASSIGNMENT = 'no_assignment';

    public const NO_MOBILE_ROLE = 'no_mobile_role';

    public const NO_PASSWORD = 'no_password';

    /** @param  array<string, scalar|null>  $context  ids and flags only -- see the class note */
    public function record(string $signIn, string $reason, ?string $phone, ?string $ip, array $context = []): void
    {
        Log::warning('sign-in refused', array_filter([
            'sign_in' => $signIn,
            'reason' => $reason,
            'phone_hash' => self::phoneHash($phone),
            'ip' => $ip,
        ] + $context, fn ($v) => $v !== null));
    }

    /**
     * Enough to group one person's attempts; not enough to recover the number.
     *
     * KEYED, not a plain hash: a Kenyan mobile number is nine digits, and an
     * unkeyed digest of nine digits is reversed by trying them all. Keyed with
     * the app key, a log reader without it cannot.
     */
    public static function phoneHash(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return null;
        }

        // The same person types 07…, 254… and +254…: hash the national number.
        return substr(hash_hmac('sha256', substr($digits, -9), (string) config('app.key')), 0, 16);
    }
}
