<?php

declare(strict_types=1);

namespace App\Observers\Money;

use App\Events\TakingsRecorded;
use App\Models\Transaction;
use App\Services\Money\SaccoLiveTakings;
use App\Support\TransDate;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Every fare a bus takes is a `transactions` row -- M-Pesa (the C2B recorder,
 * Co-op and NCBA included), cash closed at the end of a trip, wallet -- so this
 * one hook puts all of them on the SACCO's live dashboard (SaccoLiveTakings).
 *
 * After commit: a row that rolls back was never takings. And never allowed to
 * fail the write that triggered it -- a notice is worth less than the fare.
 *
 * Cheap on purpose, at ~40,000 rows a day: an old or unattributed row costs
 * nothing; a fresh one costs one primary-key read of its bus and one cache read,
 * and is published only if the SACCO's dashboard is open.
 */
class TakingsLiveObserver
{
    public bool $afterCommit = true;

    public function created(Transaction $transaction): void
    {
        try {
            if ($transaction->vehicle_id === null) {
                return;
            }

            $at = TransDate::parse($transaction->trans_date);
            if ($at === null || $at->lt(now()->subMinutes(SaccoLiveTakings::LIVE_WINDOW_MINUTES))) {
                return;
            }

            $bus = DB::table('vehicles')->where('id', $transaction->vehicle_id)->first(['sacco_id', 'plate']);
            if ($bus === null || $bus->sacco_id === null || ! SaccoLiveTakings::isWatched((int) $bus->sacco_id)) {
                return;
            }

            TakingsRecorded::dispatch($transaction, (int) $bus->sacco_id, (string) $bus->plate);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
