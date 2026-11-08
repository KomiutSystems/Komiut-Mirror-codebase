<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Wallet\WalletTopUpService;
use Illuminate\Console\Command;

/**
 * Finish the top-ups that are still in the air.
 *
 * TWO JOBS, IN THIS ORDER, and the order is the point:
 *
 *   1  RECOVER. An STK payment reaches us through exactly one path -- its
 *      callback -- so a lost callback, or a stkQuery that happened to be
 *      unreachable when the callback landed, means the passenger paid and was
 *      never credited. Nothing else would notice: wallet:reconcile only sees
 *      money that reached the ledger. This re-asks Daraja and credits what it
 *      confirms. It is the wallet's ReconcileMpesaPayments, which exists for
 *      exactly the same reason on the booking side.
 *
 *   2  AGE OUT what is still unanswered, so a passenger's screen stops
 *      waiting. Marked `timed_out` rather than `failed`, because a payment may
 *      still turn up and a timed-out attempt stays settleable.
 *
 * Recovering BEFORE expiring means an attempt whose money has already arrived
 * is credited rather than first being told it ran out of time.
 */
class SettleWalletTopUps extends Command
{
    protected $signature = 'wallet:settle-top-ups {--lookback=48 : hours of pending attempts to re-check with Daraja}';

    protected $description = 'Credit wallet top-ups whose callback was lost, then age out the rest';

    public function handle(WalletTopUpService $topUps): int
    {
        $recovered = $topUps->recoverPendingStkAttempts((int) $this->option('lookback'));

        if ($recovered > 0) {
            // Loud: every one of these is a passenger who paid and was not
            // credited until now. A steady trickle means callbacks are being
            // lost and that is worth investigating, not absorbing quietly.
            $this->warn("Recovered {$recovered} wallet top-up(s) whose callback never completed.");
        }

        $aged = $topUps->expireStaleAttempts();

        if ($aged > 0) {
            $this->info("Timed out {$aged} wallet top-up(s).");
        }

        return self::SUCCESS;
    }
}
