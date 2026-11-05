<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MpesaStkCallback;
use App\Services\Ncba\NcbaStkSettlement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Ask NCBA how a push ended, every few seconds while its prompt can be paid.
 *
 * NCBA Till STK Push has no callback, and the passenger is standing at the bus
 * watching a "waiting for payment" screen that polls OUR record
 * (StkStatusController, which never calls a provider). So the outcome has to
 * reach that record about as fast as a Daraja callback would: first check a few
 * seconds after the push, then at widening intervals until the prompt's lifetime
 * (about two minutes) is spent. Whatever is still open after that is left to
 * payments:reconcile, which keeps asking for half an hour.
 *
 * One check per job, re-dispatched with a delay, rather than one job sleeping:
 * a worker is never held, and a deploy in the middle loses nothing.
 */
final class PollNcbaStkPush implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Seconds from the push to the first check: about how long a PIN takes. */
    public const FIRST_CHECK_SECONDS = 8;

    /** The waits between later checks. Together with the first, about 2m15s. */
    public const NEXT_CHECKS = [5, 5, 5, 10, 10, 10, 15, 15, 20, 30];

    /** A failed check is retried by the next one, not by the queue. */
    public int $tries = 1;

    public function __construct(
        public readonly int $stkId,
        public readonly int $check = 0,
    ) {}

    public function handle(NcbaStkSettlement $settlement): void
    {
        $push = MpesaStkCallback::find($this->stkId);
        if ($push === null) {
            return;
        }

        $outcome = $settlement->check($push);

        if (in_array($outcome, [NcbaStkSettlement::PENDING, NcbaStkSettlement::UNKNOWN], true)
            && isset(self::NEXT_CHECKS[$this->check])) {
            self::dispatch($this->stkId, $this->check + 1)->delay(now()->addSeconds(self::NEXT_CHECKS[$this->check]));
        }
    }
}
