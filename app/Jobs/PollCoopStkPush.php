<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MpesaStkCallback;
use App\Services\Coop\CoopStkSettlement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Ask Co-op how a push ended, every few seconds while its prompt can be paid.
 *
 * Co-op does call back, but the callback is only a nudge (see
 * CoopStkSettlement): the outcome is asked for either way, so a callback that
 * is late, lost or forged costs nothing. The schedule is PollNcbaStkPush's,
 * for the same reason -- a passenger at the bus watching "waiting for payment".
 * Whatever is still open after it is left to payments:reconcile.
 *
 * One check per job, re-dispatched with a delay, rather than one job sleeping:
 * a worker is never held, and a deploy in the middle loses nothing.
 */
final class PollCoopStkPush implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Seconds from the push to the first check: about how long a PIN takes. */
    public const FIRST_CHECK_SECONDS = 8;

    /** The waits between later checks. Together with the first, about 2m15s. */
    public const NEXT_CHECKS = [5, 5, 5, 10, 10, 10, 15, 15, 20, 30];

    /** A single check that schedules no other: what a callback asks for. */
    public const ONE_OFF = 999;

    /** A failed check is retried by the next one, not by the queue. */
    public int $tries = 1;

    public function __construct(
        public readonly int $stkId,
        public readonly int $check = 0,
    ) {}

    public function handle(CoopStkSettlement $settlement): void
    {
        $push = MpesaStkCallback::find($this->stkId);
        if ($push === null) {
            return;
        }

        $outcome = $settlement->check($push);

        if (in_array($outcome, [CoopStkSettlement::PENDING, CoopStkSettlement::UNKNOWN], true)
            && isset(self::NEXT_CHECKS[$this->check])) {
            self::dispatch($this->stkId, $this->check + 1)->delay(now()->addSeconds(self::NEXT_CHECKS[$this->check]));
        }
    }
}
