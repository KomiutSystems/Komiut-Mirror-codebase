<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Vehicle;
use App\Services\Mpesa\TillRegistrar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Register one bus's till with Safaricom, off the request that saved it.
 *
 * Retried while Safaricom cannot be reached -- for about sixteen hours, at
 * widening gaps -- because a till nobody registered collects money nobody
 * sees. Anything Safaricom actually answers is final and written on the bus;
 * when the retries run out the bus says so, and the dashboard button is the
 * way back in.
 */
final class RegisterVehicleTill implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Minutes to wait before each retry while Safaricom is unreachable. */
    public const RETRY_MINUTES = [1, 5, 15, 60, 180, 720];

    public int $tries = 7;

    /**
     * Held across the whole retry window: a lock that lapsed between retries
     * let a second chain start for the same bus.
     */
    public int $uniqueFor = (1 + 5 + 15 + 60 + 180 + 720 + 60) * 60;

    public function __construct(public readonly int $vehicleId) {}

    public function uniqueId(): string
    {
        return 'till:'.$this->vehicleId;
    }

    /** For a failure that escapes handle() itself: spaced, not seven in a second. */
    public function backoff(): array
    {
        return array_map(fn (int $m) => $m * 60, self::RETRY_MINUTES);
    }

    public function handle(TillRegistrar $registrar): void
    {
        $vehicle = Vehicle::withoutGlobalScopes()->find($this->vehicleId);
        if ($vehicle === null) {
            return;
        }
        if (! $registrar->isOurs($vehicle)) {
            $registrar->markNotOurs($vehicle);

            return;
        }

        // Only a bus still waiting on THIS job is registered. A save made
        // while the job sat out a retry has already decided for itself -- a
        // person must confirm the head office, or it settled without asking
        // Safaricom -- and that decision stands: the first registration is
        // permanent, so the job never overrules it.
        if (! in_array($vehicle->till_registration_status, [TillRegistrar::PENDING, TillRegistrar::UNREACHABLE], true)) {
            return;
        }

        $wait = self::RETRY_MINUTES[$this->attempts() - 1] ?? null;
        $outcome = $registrar->register($vehicle, willRetry: $wait !== null);

        // The till changed while Safaricom was being asked: ask again, now,
        // about the till the bus has. (This job still holds the bus's lock,
        // which is why the save that changed it could not queue its own.)
        if ($outcome['status'] === TillRegistrar::SUPERSEDED) {
            $this->release(5);

            return;
        }

        if ($outcome['status'] === TillRegistrar::UNREACHABLE && $wait !== null) {
            $this->release($wait * 60);
        }
    }

    public function failed(Throwable $e): void
    {
        $vehicle = Vehicle::withoutGlobalScopes()->find($this->vehicleId);
        if ($vehicle !== null) {
            app(TillRegistrar::class)->giveUp($vehicle);
        }
    }
}
