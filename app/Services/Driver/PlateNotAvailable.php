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

    /**
     * The plate is a bus of a different SACCO from the one the agent picked.
     * The other SACCO is not named: this answer goes to an unauthenticated
     * caller, and which SACCO runs a plate is not theirs to look up.
     */
    public static function atAnotherSacco(string $plate): self
    {
        return new self("The number plate {$plate} is registered to a different SACCO. Check the SACCO you picked, "
            .'or ask the SACCO that runs this matatu to add the driver from their Crew screen.');
    }
}
