<?php

namespace App\Console\Commands;

use App\Models\Summary;
use App\Models\SummarySync;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Carbon\Carbon;
use DB;

class GenerateVehicleSummaries extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generate-vehicle-summaries
                            {--dry-run : Report the stored-vs-recomputed differences and write nothing}
                            {--date= : Recompute this Y-m-d instead of the SummarySync cursor (does not move the cursor)}
                            {--catch-up=1 : Backlog days to close in a single run, on top of today}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recompute the per-vehicle daily money summary (mpesa/cash amounts and transaction counts) from the transactions table';

    /**
     * Execute the console command.
     *
     * One `summaries` row per (vehicle_id, trans_date), each column recomputed
     * from the `transactions` rows of that day. The recompute is ABSOLUTE (it
     * SETs totals rather than incrementing them), which makes the command
     * idempotent and self-correcting: the live payment paths
     * (CopyMpesa, C2bPaymentRecorder, CoopRestPaymentsController, server.php)
     * increment a summary as money arrives, and every one of them also writes
     * the corresponding `transactions` row, so the aggregate below is a superset
     * of what those paths added.
     */
    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        $dateOption = $this->option('date');

        // --date is an inspection/repair escape hatch: it targets an arbitrary
        // day without disturbing the SummarySync cursor the scheduler relies on.
        if ($dateOption !== null) {
            $date = Carbon::parse($dateOption)->toDateString();
            [$vehicles, $changes] = $this->summariseDay($date, $dryRun);
            $this->reportDay($date, $vehicles, $changes, $dryRun);

            return 0;
        }

        $today = Carbon::today()->toDateString();
        $summary_sync = SummarySync::latest()->first();

        if ($summary_sync == null) {
            // Seed at the most recent day that has transactions, NOT the
            // earliest — starting at the beginning of history would make the
            // very first run sweep years of money rows nobody asked it to touch.
            $transaction = Transaction::orderBy('trans_date', 'DESC')->first();
            $seed = $transaction != null
                ? Carbon::parse($transaction->trans_date)->toDateString()
                : $today;

            $summary_sync = new SummarySync();
            $summary_sync->sync_date = $seed;
            $summary_sync->status = $seed >= $today;
            // A dry run must not persist the cursor either — otherwise merely
            // inspecting the data would move where the next real run starts.
            if (! $dryRun) {
                $summary_sync->save();
            }
        }

        $cursor = Carbon::parse($summary_sync->sync_date)->toDateString();
        // Clock skew or a hand-edited row must not strand the cursor in the future.
        if ($cursor > $today) {
            $cursor = $today;
        }

        // THE FIX. `sync_date` used to be written once and never again — only
        // `status` was updated afterwards — so the cursor stayed pinned at its
        // seed date and every run re-summarised that same single day forever.
        // Because this command is the only writer that ever sets a non-zero
        // `cash_amount` (every other path just initialises it to 0), all cash
        // fares after that day were missing from the dashboard entirely.
        //
        // The cursor now walks FORWARD, in bounded steps, and never backward
        // past its seed. Today is recomputed on every run so the live figure
        // stays current even while a backlog is still being closed.
        $catchUp = max(1, (int) $this->option('catch-up'));
        $dates = [];
        for ($i = 0; $i < $catchUp && $cursor < $today; $i++) {
            $dates[] = $cursor;
            $cursor = Carbon::parse($cursor)->addDay()->toDateString();
        }
        $dates[] = $today;

        foreach (array_unique($dates) as $date) {
            [$vehicles, $changes] = $this->summariseDay($date, $dryRun);
            $this->reportDay($date, $vehicles, $changes, $dryRun);
        }

        if (! $dryRun) {
            $summary_sync->sync_date = $cursor;
            $summary_sync->status = $cursor >= $today;
            $summary_sync->save();
        }

        return 0;
    }

    /**
     * Recompute every vehicle's summary for a single day.
     *
     * @return array{0:int,1:int} [vehicles seen, rows that differ]
     */
    private function summariseDay(string $transDate, bool $dryRun): array
    {
        // Half-open window. `transactions.trans_date` is a timestamp, so the old
        // inclusive whereBetween($transDate, $transDate+1day) also matched rows
        // at exactly next-day 00:00:00 and counted them into both days.
        $nextDate = Carbon::parse($transDate)->addDay()->toDateString();

        // Each metric is derived from its own expression. mpesa_* is keyed on
        // mpesa_id, cash_* on cash_id; the *_count columns count rows while the
        // *_totals columns sum amounts. (The previous revision both wrote
        // mpesa_count into cash_txn and left a trailing comma in this raw
        // select, which made the statement a hard SQL syntax error.)
        $transactions = Transaction::select('vehicle_id', DB::raw('SUM(amount) as totals,
            SUM(CASE WHEN mpesa_id>0 THEN amount ELSE 0 END) as mpesa_totals,
            SUM(CASE WHEN cash_id>0 THEN amount ELSE 0 END) as cash_totals,
            SUM(CASE WHEN cash_id>0 THEN 1 ELSE 0 END) as cash_count,
            SUM(CASE WHEN mpesa_id>0 THEN 1 ELSE 0 END) as mpesa_count'))
            ->whereNotNull('vehicle_id')
            ->where('trans_date', '>=', $transDate)
            ->where('trans_date', '<', $nextDate)
            ->groupBy('vehicle_id')->get();

        $changes = 0;

        foreach($transactions as $transaction){
            // A row is looked up (or built) per vehicle. The previous revision
            // iterated the day's EXISTING summaries in an outer loop and
            // reassigned the same $summary object for every vehicle in turn, so
            // one vehicle's money was written over another vehicle's row -- and
            // because it only ever mutated pre-existing rows, it created none.
            $summary = Summary::where('vehicle_id', $transaction->vehicle_id)
                ->where('trans_date', $transDate)
                ->first();

            $recomputed = [
                'mpesa_amount' => (float) $transaction->mpesa_totals,
                'cash_amount' => (float) $transaction->cash_totals,
                'mpesa_txn' => (int) $transaction->mpesa_count,
                'cash_txn' => (int) $transaction->cash_count,
            ];

            if ($dryRun) {
                $changes += $this->reportDifference($transaction->vehicle_id, $transDate, $summary, $recomputed);
                continue;
            }

            if ($summary == null) {
                $summary = new Summary();
                $summary->vehicle_id = $transaction->vehicle_id;
                $summary->trans_date = $transDate;
            }

            // expense_fee_amount is deliberately untouched: it is not derived
            // from `transactions`, so a recompute must not clobber it.
            $summary->mpesa_amount = $recomputed['mpesa_amount'];
            $summary->cash_amount = $recomputed['cash_amount'];
            $summary->mpesa_txn = $recomputed['mpesa_txn'];
            $summary->cash_txn = $recomputed['cash_txn'];
            $summary->save();
        }

        return [$transactions->count(), $changes];
    }

    private function reportDay(string $date, int $vehicles, int $changes, bool $dryRun): void
    {
        // Two short lines rather than one long one: the console formatter wraps
        // long output, which would split these phrases mid-sentence.
        if ($dryRun) {
            $this->info("Dry run {$date}: {$vehicles} vehicle(s), {$changes} row(s) would change.");
            $this->info('Nothing was written.');

            return;
        }

        $this->info("{$date}: {$vehicles} vehicle(s) summarised.");
    }

    /**
     * Print the stored-vs-recomputed diff for one (vehicle, date) pair and
     * return 1 when they disagree. This is the read-only path a human uses to
     * decide whether historical rows are worth rewriting; rewriting money
     * records stays a deliberate act, never a side effect of running a fix.
     *
     * @param  array<string, float|int>  $recomputed
     */
    private function reportDifference($vehicleId, string $transDate, ?Summary $summary, array $recomputed): int
    {
        if ($summary == null) {
            $this->line("vehicle {$vehicleId} {$transDate}: MISSING -> would create "
                . "mpesa_amount={$recomputed['mpesa_amount']} cash_amount={$recomputed['cash_amount']} "
                . "mpesa_txn={$recomputed['mpesa_txn']} cash_txn={$recomputed['cash_txn']}");

            return 1;
        }

        $diffs = [];
        foreach ($recomputed as $column => $value) {
            $stored = $summary->{$column};
            // Loose comparison on purpose: the stored columns come back from
            // PostgreSQL as strings/floats and only the VALUE matters here.
            if ((string) $stored !== (string) $value) {
                $diffs[] = "{$column}: {$stored} -> {$value}";
            }
        }

        if ($diffs === []) {
            return 0;
        }

        $this->line("vehicle {$vehicleId} {$transDate}: " . implode(', ', $diffs));

        return 1;
    }
}
