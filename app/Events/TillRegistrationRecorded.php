<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A bus's till registration status changed (TillRegistrar::record).
 *
 * The registrar writes that status with a query, past the model's events, on
 * purpose (see the note in record()) -- so nothing observing Vehicle hears
 * it. Whoever needs to react to "this till was refused / is registered now"
 * listens here instead. Ids only: listeners re-read what they need.
 */
final class TillRegistrationRecorded
{
    use Dispatchable;

    public function __construct(
        public readonly int $vehicleId,
        public readonly ?int $saccoId,
        public readonly ?string $from,
        public readonly string $status,
    ) {}
}
