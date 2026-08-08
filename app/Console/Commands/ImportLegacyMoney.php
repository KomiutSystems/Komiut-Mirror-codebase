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
 */
class ImportLegacyMoney extends Command
{
    protected $signature = 'legacy:import-money
        {--file= : Path to the gzipped JSONL export}
        {--batch=2000 : Rows per insert}
        {--dry-run : Count what would be imported and write nothing}';

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
        $path = (string) $this->option('file');
        if ($path === '' || ! is_readable($path)) {
            $this->error('Pass a readable --file=<export.jsonl.gz>.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
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
        $cash = DB::table('transactions')->whereNotNull('cash_id')->update(['cash_id' => null]);

        // A transaction inside the window can reference an M-Pesa row outside it.
        $mpesa = DB::update('UPDATE transactions SET mpesa_id = NULL
            WHERE mpesa_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM mpesas m WHERE m.id = transactions.mpesa_id)');

        $this->line("cleared {$cash} cash_id and {$mpesa} out-of-window mpesa_id reference(s)");
    }

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
