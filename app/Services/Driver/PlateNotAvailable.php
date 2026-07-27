<?php

declare(strict_types=1);

namespace App\Services\Driver;

use RuntimeException;

/**
 * A plate is registered to a vehicle this brand cannot see.
 *
 * `vehicles.plate` is globally unique but Vehicle is brand-scoped, so a plate
 * belonging to another brand reads as "not found" and a blind insert would hit
 * the unique index. Onboarding is a public endpoint; this turns that into an
 * answerable 409 instead of a 500.
 */
final class PlateNotAvailable extends RuntimeException
{
    public static function for(string $plate): self
    {
        return new self("The number plate {$plate} is already registered.");
    }
}
