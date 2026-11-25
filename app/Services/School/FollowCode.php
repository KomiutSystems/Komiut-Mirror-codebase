<?php

declare(strict_types=1);

namespace App\Services\School;

use App\Models\Sacco;
use App\Models\Vehicle;

/**
 * The follow code a school hands its parents for one bus.
 *
 * Eight characters from an alphabet with no look-alikes (no 0/O, 1/I/L), so it
 * survives being read out over the phone or copied off a printed letter:
 * 31^8 ~ 8.5e11 codes. Guessing is further bounded by the follow endpoint's
 * throttle. Compared in constant time, case-insensitively, ignoring spaces and
 * dashes ("ab3k-7pqx" works).
 *
 * The code is a shared secret, not a password: the school must be able to read
 * it back to share it, so it is stored as issued. Rotating it (and optionally
 * revoking existing followers) is how a school responds to a leak.
 */
final class FollowCode
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const LENGTH = 8;

    public static function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /** What a person typed, in stored form. */
    public static function normalise(?string $typed): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', (string) $typed));
    }

    public static function matches(Vehicle $vehicle, ?string $typed): bool
    {
        $stored = (string) $vehicle->follow_code;

        return $stored !== '' && hash_equals($stored, self::normalise($typed));
    }

    /**
     * Give a school bus a fresh, unused code and save it. Returns the code.
     * Retries on the (astronomically rare) collision with another bus's code.
     */
    public static function issue(Vehicle $vehicle): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = self::generate();
            if (! Vehicle::withoutGlobalScopes()->where('follow_code', $code)->exists()) {
                $vehicle->forceFill(['follow_code' => $code])->save();

                return $code;
            }
        }

        throw new \RuntimeException('Could not issue a unique follow code.');
    }

    /** Is this vehicle a school bus? One query when the sacco is not loaded. */
    public static function isSchoolBus(Vehicle $vehicle): bool
    {
        $sacco = $vehicle->relationLoaded('sacco')
            ? $vehicle->sacco
            : Sacco::withoutGlobalScopes()->find($vehicle->sacco_id, ['id', 'kind']);

        return $sacco !== null && $sacco->kind === Sacco::KIND_SCHOOL;
    }
}
