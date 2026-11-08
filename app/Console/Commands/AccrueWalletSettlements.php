<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Wallet\WalletSettlementService;
use App\Support\Shillings;
use Illuminate\Console\Command;

/**
 * Turn closed days of wallet fares into money owed to SACCOs.
 *
 * ONLY CLOSED BUSINESS DAYS. A day that is still running would accrue a partial
 * figure, get approved and paid, and then take more fares with no row left to
 * claim them -- they would sit unsettled forever behind an already-paid period.
 *
 * Safe to run repeatedly: a fare is claimed by exactly one settlement
 * (wallet_transactions.wallet_settlement_id) and the accrual only ever selects
 * unclaimed ones, so a second pass over the same day finds nothing.
 *
 * IT SENDS NO MONEY. Accrual states what is owed; a settlement has to be
 * approved by a human and then paid, which is a separate act with its own audit
 * row. See WalletSettlementService.
 */
class AccrueWalletSettlements extends Command
{
    protected $signature = 'wallet:accrue-settlements {--days=14 : how many closed business days to sweep in one run}';

    protected $description = 'Accrue wallet fares from closed business days into per-SACCO settlements';

    public function handle(WalletSettlementService $settlements): int
    {
        $result = $settlements->accrue(null, max(1, (int) $this->option('days')));

        if ($result['settlements'] === 0) {
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Accrued %d settlement(s): %d fare(s), KES %s owed.',
            $result['settlements'],
            $result['fares'],
            number_format(Shillings::toShillings($result['cents'])),
        ));

        // Said out loud because nothing is payable until somebody looks at it,
        // and a figure nobody reads is a figure nobody approves.
        $this->line('These are PENDING. Approve them before any payout.');

        return self::SUCCESS;
    }
}
