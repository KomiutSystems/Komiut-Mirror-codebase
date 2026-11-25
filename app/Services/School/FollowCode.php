<?php

declare(strict_types=1);

namespace App\Services\School;

use App\Models\Sacco;
use App\Models\Vehicle;
use Illuminate\Database\UniqueConstraintViolationException;

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

    /**
     * Give a bus a code only if it has none, and return whatever it holds
     * afterwards. A conditional write: two staff opening the school's bus list
     * at once must not each issue a different code and be shown different
     * ones. A clash with another bus's code is retried with a fresh one.
     */
    public static function issueIfMissing(int $vehicleId): ?string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                Vehicle::withoutGlobalScopes()->whereKey($vehicleId)->whereNull('follow_code')
                    ->update(['follow_code' => self::generate()]);
                break;
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        return Vehicle::withoutGlobalScopes()->whereKey($vehicleId)->value('follow_code');
    }

    /** Is this vehicle a school bus? The loaded SACCO if there is one, else SchoolBuses' cached answer. */
    public static function isSchoolBus(Vehicle $vehicle): bool
    {
        if ($vehicle->relationLoaded('sacco')) {
            return $vehicle->sacco !== null && $vehicle->sacco->kind === Sacco::KIND_SCHOOL;
        }

        return SchoolBuses::isSchoolBus((int) $vehicle->id);
    }
}
