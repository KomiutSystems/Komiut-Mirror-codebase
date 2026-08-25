<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One `summaries` row per (vehicle_id, trans_date), enforced by the database.
 *
 * THE BUG. C2bPaymentRecorder rolled each confirmation into the day's summary
 * with a read-modify-write and no lock. Two payments for the same bus on the
 * same day, arriving together, both read the old total and both wrote their own
 * back — one payment gone from the takings. And when the row did not exist yet,
 * both INSERTED. Production currently holds 5 such duplicate groups, all on
 * 2026-08-04, the worst being vehicle 877 with 47 rows for that one day.
 *
 * It matters because SummariesAPIController reports
 * `SUM(mpesa_amount) ... GROUP BY vehicle_id`, so every duplicate row is counted
 * again. GenerateVehicleSummaries does not heal it: its recompute is absolute,
 * but it fetches the row with `->first()`, so it corrects ONE of the duplicates
 * and leaves the rest holding whatever the increment path last wrote.
 *
 * WHY THE UNIQUE INDEX WAS SKIPPED BEFORE, AND WHY THAT NO LONGER HOLDS.
 * 2026_08_07_120000 declined to make `summaries_vehicle_id_trans_date_index`
 * unique, citing GenerateVehicleSummaries' loop reassigning `vehicle_id` on one
 * shared `$summary` object. That loop has since been rewritten — it now builds a
 * row per vehicle — so the writer that produced unsatisfiable data is gone. What
 * remained was the historical rows it had already produced, which is what up()
 * clears before taking the constraint.
 *
 * WHY DELETING THESE ROWS IS SAFE WITHOUT A BACKUP. This project has no database
 * backups (see 2026_08_07_123000), so destructive migrations deserve suspicion.
 * `summaries` is the one money table that is DERIVED: every column except
 * expense_fee_amount is a pure aggregate of `transactions`, which is untouched
 * here and remains the source of truth. Rather than trusting the arithmetic of a
 * merge, up() deletes the extra rows and then RECOMPUTES each survivor from
 * `transactions` — the identical expression GenerateVehicleSummaries uses. The
 * result does not depend on what the duplicates held, so a wrong duplicate
 * cannot poison the survivor.
 *
 * expense_fee_amount is NOT recomputed: it has no source in `transactions`. It
 * is carried over from the surviving row, which is the same row the recompute
 * command would have kept and left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('summaries')) {
            return;
        }

        // 1. Which (vehicle_id, trans_date) pairs are duplicated? Collected
        //    BEFORE the delete, because afterwards there is nothing left to
        //    identify them by.
        $duplicated = DB::table('summaries')
            ->select('vehicle_id', 'trans_date')
            ->groupBy('vehicle_id', 'trans_date')
            ->havingRaw('count(*) > 1')
            ->get();

        // 2. Keep the lowest id per pair; drop the rest. Lowest id = the row
        //    GenerateVehicleSummaries' ->first() has been maintaining, so it is
        //    the one most likely already correct and the one carrying the
        //    original expense_fee_amount.
        DB::table('summaries')
            ->whereNotIn('id', function ($q): void {
                $q->from('summaries')->selectRaw('min(id)')->groupBy('vehicle_id', 'trans_date');
            })
            ->delete();

        // 3. Recompute each survivor straight from `transactions` — the same
        //    expression GenerateVehicleSummaries uses, so the result does not
        //    depend on anything the deleted duplicates held. Half-open window:
        //    `transactions.trans_date` is a timestamp, so a closed upper bound
        //    would also match next-day 00:00:00 and count it into both days.
        //    Looped rather than done in one UPDATE..FROM because that syntax is
        //    PostgreSQL-only, and this list is short by nature.
        foreach ($duplicated as $pair) {
            $day = Carbon::parse((string) $pair->trans_date)->toDateString();

            $agg = DB::table('transactions')
                ->where('vehicle_id', $pair->vehicle_id)
                ->where('trans_date', '>=', $day)
                ->where('trans_date', '<', Carbon::parse($day)->addDay()->toDateString())
                ->selectRaw('coalesce(sum(case when mpesa_id > 0 then amount else 0 end), 0) as mpesa_totals')
                ->selectRaw('coalesce(sum(case when cash_id  > 0 then amount else 0 end), 0) as cash_totals')
                ->selectRaw('coalesce(sum(case when mpesa_id > 0 then 1 else 0 end), 0) as mpesa_count')
                ->selectRaw('coalesce(sum(case when cash_id  > 0 then 1 else 0 end), 0) as cash_count')
                ->first();

            DB::table('summaries')
                ->where('vehicle_id', $pair->vehicle_id)
                ->where('trans_date', $pair->trans_date)
                ->update([
                    'mpesa_amount' => (float) ($agg->mpesa_totals ?? 0),
                    'cash_amount' => (float) ($agg->cash_totals ?? 0),
                    'mpesa_txn' => (int) ($agg->mpesa_count ?? 0),
                    'cash_txn' => (int) ($agg->cash_count ?? 0),
                    'updated_at' => Carbon::now(),
                ]);
        }

        // 3. Take the constraint. Named distinctly from the plain composite so
        //    both can coexist through the deploy; step 4 removes the redundant one.
        Schema::table('summaries', function (Blueprint $table): void {
            $table->unique(['vehicle_id', 'trans_date'], 'summaries_vehicle_id_trans_date_unique');
        });

        // 4. The plain composite is now strictly redundant — a unique btree on
        //    the same columns in the same order answers every query it did. On a
        //    table written once per settled payment, carrying both costs a write
        //    per confirmation for nothing.
        $this->dropIndexIfExists('summaries_vehicle_id_trans_date_index');
    }

    public function down(): void
    {
        if (! Schema::hasTable('summaries')) {
            return;
        }

        Schema::table('summaries', function (Blueprint $table): void {
            $table->index(['vehicle_id', 'trans_date'], 'summaries_vehicle_id_trans_date_index');
        });

        $this->dropIndexIfExists('summaries_vehicle_id_trans_date_unique');

        // The deleted duplicate rows are deliberately NOT recreated. They were
        // double-counted aggregates of `transactions`, never source data; putting
        // them back would restore the inflation this migration removed. If a
        // specific day needs rebuilding, that is
        // `php artisan app:generate-vehicle-summaries --date=YYYY-MM-DD`.
    }

    private function dropIndexIfExists(string $name): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('drop index if exists '.$name);

            return;
        }

        try {
            Schema::table('summaries', function (Blueprint $table) use ($name): void {
                $table->dropIndex($name);
            });
        } catch (\Throwable) {
            // Absent already, or a driver that cannot introspect it — either way
            // the index is not there to cost anything.
        }
    }
};
