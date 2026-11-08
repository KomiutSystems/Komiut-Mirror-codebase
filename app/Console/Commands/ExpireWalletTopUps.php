<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Wallet\WalletTopUpService;
use Illuminate\Console\Command;

/**
 * Stop a top-up spinning forever.
 *
 * An STK prompt dies on the handset in about a minute, but nothing tells us
 * when nobody answers it -- Daraja's callback only fires on an outcome, and
 * NCBA has no callback at all. So a pending attempt that nobody acted on stays
 * pending until something ages it out, and the passenger's screen waits with it.
 *
 * MARKS THEM `timed_out`, NOT `failed`, and the distinction matters: a late
 * payment still settles a timed-out attempt (WalletTopUpStatus::mayStillSettle,
 * and the matcher in WalletTopUpService). Calling it failed was a guess about
 * the prompt; the money is not a guess, and a passenger whose payment lands two
 * minutes after the deadline must still be credited.
 */
class ExpireWalletTopUps extends Command
{
    protected $signature = 'wallet:expire-top-ups';

    protected $description = 'Age out wallet top-ups nobody ever answered';

    public function handle(WalletTopUpService $topUps): int
    {
        $aged = $topUps->expireStaleAttempts();

        if ($aged > 0) {
            $this->info("Timed out {$aged} wallet top-up(s).");
        }

        return self::SUCCESS;
    }
}
