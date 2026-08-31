<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recover Safaricom's running balance for payments recorded before we kept it.
 *
 * OrgAccountBalance arrives in every C2B confirmation and was never stored — but
 * C2bConfirmationController writes the raw body to `mpesa_logs` before doing
 * anything else, precisely so a field we failed to keep can be recovered later.
 * This is that later.
 *
 * SAFE BY CONSTRUCTION. It writes one column, which was NULL for every row until
 * this week, only where it is still NULL, from a receipt we already hold. It
 * moves no money, changes no attribution, and re-running it matches nothing. It
 * is not gated behind a confirmation flag for that reason; --dry-run is for
 * inspection, not as a guard.
 *
 * The reach is bounded by log retention (logs:prune), so this fills the recent
 * window and leaves older rows NULL. That is honest: TillLedgerAudit breaks its
 * chain at a NULL rather than comparing across it, so an unfilled row makes the
 * audit skip a comparison instead of inventing one.
 */
class BackfillOrgAccountBalance extends Command
{
    protected $signature = 'payments:backfill-balances
        {--from= : Only logs from this Y-m-d onward}
        {--chunk=1000 : Log rows read per batch}
        {--dry-run : Report what would be filled and write nothing}';

    protected $description = 'Fill mpesas.OrgAccountBalance from the raw callback bodies kept in mpesa_logs';

    private int $filled = 0;

    private int $noBalance = 0;

    private int $scanned = 0;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $from = $this->option('from');

        DB::table('mpesa_logs')
            ->select(['id', 'trans_id', 'log'])
            ->whereNotNull('log')
            ->when($from !== null, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->orderBy('id')
            ->chunk($chunk, function ($logs) use ($dryRun): void {
                $pairs = $this->parse($logs);

                if ($pairs !== []) {
                    $this->filled += $dryRun ? $this->countFillable($pairs) : $this->fill($pairs);
                }
            });

        $this->info('Log bodies scanned     : '.number_format($this->scanned));
        $this->info(($dryRun ? 'Would fill             : ' : 'Balances filled        : ').number_format($this->filled));
        $this->line('Bodies carrying none   : '.number_format($this->noBalance));

        if ($dryRun) {
            $this->warn('DRY RUN — nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * receipt => balance, for bodies that carry one.
     *
     * @return array<string, float>
     */
    private function parse($logs): array
    {
        $pairs = [];

        foreach ($logs as $log) {
            $this->scanned++;

            $body = json_decode((string) $log->log, true);
            if (! is_array($body)) {
                continue;
            }

            $receipt = trim((string) ($body['TransID'] ?? $log->trans_id ?? ''));
            $balance = $body['OrgAccountBalance'] ?? null;

            if ($receipt === '') {
                continue;
            }

            if (! is_numeric($balance)) {
                $this->noBalance++;

                continue;
            }

            $pairs[$receipt] = (float) $balance;
        }

        return $pairs;
    }

    /** @param array<string, float> $pairs */
    private function countFillable(array $pairs): int
    {
        return DB::table('mpesas')
            ->whereIn('TransID', array_keys($pairs))
            ->whereNull('OrgAccountBalance')
            ->count();
    }

    /**
     * One fully-parameterised statement per batch.
     *
     * Postgres takes an UPDATE ... FROM (VALUES ...) join, which fills a whole
     * batch in a single round trip with every receipt and balance passed as a
     * binding — no SQL is ever assembled from data. Other drivers (sqlite, in
     * tests) fall back to a row at a time, which is slower and equally correct.
     *
     * `whereNull` on both paths is what makes a re-run a no-op.
     *
     * @param  array<string, float>  $pairs
     */
    private function fill(array $pairs): int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $done = 0;
            foreach ($pairs as $receipt => $balance) {
                $done += DB::table('mpesas')
                    ->where('TransID', $receipt)
                    ->whereNull('OrgAccountBalance')
                    ->update(['OrgAccountBalance' => $balance]);
            }

            return $done;
        }

        $rows = [];
        $bindings = [];
        foreach ($pairs as $receipt => $balance) {
            $rows[] = '(?, ?::numeric)';
            $bindings[] = $receipt;
            $bindings[] = $balance;
        }

        return DB::affectingStatement(
            'UPDATE mpesas AS m
                SET "OrgAccountBalance" = v.bal
               FROM (VALUES '.implode(',', $rows).') AS v(receipt, bal)
              WHERE m."TransID" = v.receipt
                AND m."OrgAccountBalance" IS NULL',
            $bindings
        );
    }
}
