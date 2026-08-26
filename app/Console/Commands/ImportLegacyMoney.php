<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bulk import of the money tables (mpesas, transactions, summaries) for a
 * bounded recent window — NOT the full history. Legacy holds ~20M M-Pesa rows
 * and ~21M transactions; a test environment wants a realistic recent slice, not
 * five years of it.
 *
 * Reads gzipped JSON-lines rather than one JSON document: at this row count a
 * json_decode of the whole file would need gigabytes. Rows stream through in
 * batches instead, so memory stays flat regardless of window size.
 *
 * Legacy ids are preserved (same rule as every other slice) and the sequences
 * re-synced afterwards.
 *
 * ---------------------------------------------------------------------------
 * TWO HAZARDS — WHY --confirm-legacy-migration EXISTS
 * ---------------------------------------------------------------------------
 *
 * Neither announces itself, and neither is reversible from anything the
 * database holds afterwards. They are why a writing run has to be asked for in
 * words rather than reached by a tab-completion or by a deploy script that grew
 * a line.
 *
 * 1. resequence() moves a sequence to MAX(id) — DOWNWARDS if that is where
 *    MAX(id) sits. `setval` sets a sequence to whatever it is handed, and this
 *    hands it COALESCE(MAX(id), 1) computed over the WHOLE table, not over the
 *    window just imported.
 *
 *    That directly attacks the protection the cutover depends on. Because this
 *    command preserves legacy ids, the live sequences have to be advanced CLEAR
 *    of the legacy id range before imported rows and live C2B confirmations can
 *    share a table — the gap between the sequence and MAX(id) IS the protection.
 *    resequence() closes that gap by definition: it is a no-op only while the
 *    gap is already zero.
 *
 *    Collapse it and the next Daraja confirmation nextval()s an id an imported
 *    row already holds. The INSERT violates mpesas_pkey, C2bPaymentRecorder
 *    catches Throwable, and C2bConfirmationController still answers Safaricom
 *    with "Success" — so the customer is debited, the payment does not exist
 *    here, and an mpesa_logs row is the only trace it ever arrived.
 *
 *    Measured read-only on the live Frankfurt database, 2026-08-26:
 *    mpesas_id_seq 25,000,702 with max(id) 25,000,702, transactions_id_seq
 *    26,000,702 with max(id) 26,000,702. The day-1 advance to the 25M/26M
 *    floors has been fully consumed by live traffic, so running this TODAY
 *    happens to be a no-op on those two. That is timing, not safety: it is a
 *    no-op only until the next advance, and the next advance is what the next
 *    slice of this migration needs.
 *
 *    Related, same blast radius: insertOrIgnore compiles on PostgreSQL to
 *    `on conflict do nothing` with NO conflict target (PostgresGrammar::
 *    compileInsertOrIgnore), so it swallows a PRIMARY KEY collision as readily
 *    as the TransID one the comment beside it is written for. An imported row
 *    whose id already belongs to a live row is discarded in silence, and the
 *    command still exits 0.
 *
 * 2. repair() nulls cash_id across the ENTIRE transactions table.
 *
 *    `whereNotNull('cash_id')` carries no bound — not on id, not on trans_date,
 *    not on anything. It is written for the assumption stated beside it (cashes
 *    was never migrated, so every cash_id is a dangling legacy pointer), and
 *    that assumption has an expiry date: DriverTripController::confirmCash()
 *    creates a Cash row and a transaction pointing at it, on the live driver
 *    path, today.
 *
 *    The live table holds 0 such rows as of 2026-08-26 (counted read-only), so
 *    this is latent rather than realised — it arrives with the first driver
 *    cash fare, which is precisely when nobody will re-read this command.
 *
 *    What it costs then: cash_id is the ONLY thing marking a transaction as
 *    cash. Every read that splits cash from M-Pesa keys off it and nothing else
 *    — GenerateVehicleSummaries' cash_totals/cash_count, the dashboard home
 *    split, DriverCashController's earnings, the transactions list join. The
 *    money in transactions.amount is untouched, but the fare leaves the cash
 *    column for no column at all, and the row keeps nothing to restore it from.
 */
class ImportLegacyMoney extends Command
{
    protected $signature = 'legacy:import-money
        {--file= : Path to the gzipped JSONL export}
        {--batch=2000 : Rows per insert}
        {--dry-run : Count what would be imported and write nothing}
        {--confirm-legacy-migration : Required for a writing run. See the two hazards in the class docblock}';

    protected $description = 'Import a recent window of mpesas, transactions and summaries';

    /** Columns we accept per table — anything else in the export is ignored. */
    private const COLUMNS = [
        'mpesas' => ['id', 'TransID', 'MSISDN', 'TransAmount', 'TransTime', 'FirstName', 'MiddleName',
            'LastName', 'ThirdPartyTransID', 'InvoiceNumber', 'BillRefNumber', 'BusinessShortCode',
            'TransactionType', 'created_at'],
        'transactions' => ['id', 'vehicle_id', 'cash_id', 'mpesa_id', 'amount', 'points', 'trans_date',
            'redeemed', 'summarized', 'created_at'],
        'summaries' => ['id', 'vehicle_id', 'mpesa_amount', 'cash_amount', 'mpesa_txn', 'cash_txn',
            'expense_fee_amount', 'trans_date', 'created_at'],
    ];

    private const SECTIONS = [
        '###MPESAS' => 'mpesas',
        '###TRANSACTIONS' => 'transactions',
        '###SUMMARIES' => 'summaries',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Fail closed, the same shape copy:mpesa uses on an unset LEGACY_BASE_URL:
        // say what the command would do, and refuse. A writing run of this is a
        // scheduled step in a reviewed migration, so requiring it to be asked for
        // by name costs the one person who means it nothing, and is the only thing
        // standing between the two hazards above and a mistyped command.
        //
        // --dry-run is deliberately exempt. It returns before repair() and
        // resequence() and writes nothing at all, so it is the safe way to find
        // out what an export contains — and a guard that also blocks the safe
        // path teaches people to reach for the flag reflexively, which is exactly
        // the habit this is trying to prevent.
        if (! $dryRun && ! $this->option('confirm-legacy-migration')) {
            $this->error('legacy:import-money writes to the live money tables. Re-run with --confirm-legacy-migration.');
            $this->line('It rewinds the mpesas/transactions/summaries id sequences, which can reopen an');
            $this->line('id collision that loses M-Pesa confirmations, and it nulls cash_id on EVERY');
            $this->line('transaction in the table, including live driver cash fares.');
            $this->line('Read the class docblock, and use --dry-run to inspect the export first.');

            return self::FAILURE;
        }

        $path = (string) $this->option('file');
        if ($path === '' || ! is_readable($path)) {
            $this->error('Pass a readable --file=<export.jsonl.gz>.');

            return self::FAILURE;
        }

        $batchSize = max(100, (int) $this->option('batch'));

        $handle = gzopen($path, 'rb');
        if ($handle === false) {
            $this->error('Could not open the export.');

            return self::FAILURE;
        }

        $counts = ['mpesas' => 0, 'transactions' => 0, 'summaries' => 0, 'malformed' => 0];
        $table = null;
        $batch = [];

        $flush = function () use (&$batch, &$table, $dryRun): void {
            if ($batch === [] || $table === null) {
                return;
            }
            if (! $dryRun) {
                // insertOrIgnore, not insert: mpesas.TransID is unique and the
                // window may overlap a previous run. A duplicate must not abort
                // a million-row import.
                DB::table($table)->insertOrIgnore($batch);
            }
            $batch = [];
        };

        while (($line = gzgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '###')) {
                $flush();
                $table = self::SECTIONS[$line] ?? null;
                if ($table !== null) {
                    $this->line("reading {$table} ...");
                }

                continue;
            }

            if ($table === null) {
                continue;
            }

            $row = json_decode($line, true);
            if (! is_array($row)) {
                $counts['malformed']++;

                continue;
            }

            $batch[] = $this->shape($table, $row);
            $counts[$table]++;

            if (count($batch) >= $batchSize) {
                $flush();
                if ($counts[$table] % 100000 === 0) {
                    $this->line("  {$table}: {$counts[$table]} rows");
                }
            }
        }

        $flush();
        gzclose($handle);

        $this->newLine();
        $this->table(['table', 'rows'], collect($counts)->map(fn ($v, $k) => [$k, number_format($v)])->values()->all());

        if ($dryRun) {
            $this->info('Dry run — nothing written.');

            return self::SUCCESS;
        }

        $this->repair();
        $this->resequence();

        return self::SUCCESS;
    }

    /** Normalise one row to exactly the table's columns, in a fixed key order. */
    private function shape(string $table, array $row): array
    {
        $out = [];
        foreach (self::COLUMNS[$table] as $col) {
            $out[$col] = $row[$col] ?? null;
        }

        // NOT NULL with no default on the new side; legacy has stray nulls.
        if ($table === 'summaries') {
            foreach (['mpesa_amount', 'cash_amount', 'mpesa_txn', 'cash_txn'] as $c) {
                $out[$c] = $out[$c] ?? 0;
            }
            $out['expense_fee_amount'] = $out['expense_fee_amount'] ?? '0';
        }
        if ($table === 'transactions') {
            $out['amount'] = $out['amount'] ?? 0;
            $out['redeemed'] = (bool) ($out['redeemed'] ?? false);
            $out['summarized'] = (bool) ($out['summarized'] ?? false);
        }

        $out['updated_at'] = $out['created_at'] ?? now();

        return $out;
    }

    /**
     * Set-based cleanup of references that point outside the imported window.
     * Done in SQL rather than per-row in PHP: checking a million rows against a
     * million-entry lookup table would cost more memory than the import itself.
     */
    private function repair(): void
    {
        // cashes was not migrated, so every cash_id is dangling. The money is
        // still correct — transactions.amount carries it — but the pointer is
        // not, and leaving it invites a join that silently drops rows.
        //
        // HAZARD 2 in the class docblock: this is unscoped, and "every cash_id is
        // a dangling legacy pointer" is no longer a property of the schema — it
        // is a property of TODAY, true only while DriverTripController::
        // confirmCash() has not yet written one. Bound this to the imported
        // window before it runs against a database with live cash in it.
        $cash = DB::table('transactions')->whereNotNull('cash_id')->update(['cash_id' => null]);

        // A transaction inside the window can reference an M-Pesa row outside it.
        $mpesa = DB::update('UPDATE transactions SET mpesa_id = NULL
            WHERE mpesa_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM mpesas m WHERE m.id = transactions.mpesa_id)');

        $this->line("cleared {$cash} cash_id and {$mpesa} out-of-window mpesa_id reference(s)");
    }

    /**
     * HAZARD 1 in the class docblock: `setval` lowers a sequence as willingly as
     * it raises one, and MAX(id) is read over the whole table. Do not let this
     * run after the sequences have been deliberately advanced past the legacy id
     * range — it undoes that advance and hands the next live confirmation an id
     * that is already taken.
     */
    private function resequence(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        foreach (['mpesas', 'transactions', 'summaries'] as $t) {
            DB::statement("SELECT setval(pg_get_serial_sequence('{$t}', 'id'), COALESCE((SELECT MAX(id) FROM {$t}), 1))");
        }
        $this->line('sequences re-synced');
    }
}
