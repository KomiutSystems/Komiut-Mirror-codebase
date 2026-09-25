<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Mpesa\StatementImporter;
use Illuminate\Console\Command;

/**
 * Record the completed payments on M-Pesa portal statements that never reached
 * us as confirmations. See StatementImporter.
 *
 *   php artisan payments:import-statement /tmp/statements/*.xls
 *   php artisan payments:import-statement /tmp/statements/*.xls --write
 *
 * Dry run unless --write. Each file is one till; a file whose times disagree
 * with ours, or whose short code matches no single bus, is refused on its own
 * and the others still run.
 */
class ImportMpesaStatement extends Command
{
    protected $signature = 'payments:import-statement
        {files* : M-Pesa portal statement exports (.xls, .xlsx or .csv), one till each}
        {--write : Record the payments we do not hold (default: dry run)}
        {--show : List every missing receipt}';

    protected $description = 'Recover undelivered C2B payments from M-Pesa portal statements';

    public function handle(StatementImporter $importer): int
    {
        $rows = [];
        $t = ['payments' => 0, 'held' => 0, 'missing' => 0, 'kes' => 0.0, 'recorded' => 0, 'failed' => 0];
        $refused = 0;

        foreach ((array) $this->argument('files') as $file) {
            if (! is_file($file)) {
                $this->error("No such file: {$file}");
                $refused++;

                continue;
            }
            $r = $importer->run($file, (bool) $this->option('write'));
            if (! $r['ok']) {
                $refused++;
            }
            foreach (['payments', 'held', 'missing', 'recorded', 'failed'] as $k) {
                $t[$k] += $r[$k];
            }
            $t['kes'] += $r['missing_kes'];
            $rows[] = [basename($file), $r['short_code'] ?? '-', $r['plate'] ?? 'NO BUS', $r['payments'], $r['held'], $r['missing'],
                number_format($r['missing_kes'], 0), $r['drift'] === null ? '-' : $r['drift'].'s', $r['recorded'], $r['ok'] ? ($r['message'] ?: 'ok') : 'REFUSED: '.$r['message']];

            if ($this->option('show')) {
                foreach ($r['rows'] as $m) {
                    $this->line("    {$m['TransTime']}  {$m['TransID']}  KES {$m['TransAmount']}");
                }
            }
        }

        $this->table(['File', 'Till', 'Bus', 'Completed', 'We hold', 'Missing', 'Missing KES', 'Clock', 'Recorded', 'Note'], $rows);
        $this->info(sprintf('Total: %d completed on the statements, we hold %d, missing %d (KES %s), recorded %d, failed %d.%s',
            $t['payments'], $t['held'], $t['missing'], number_format($t['kes'], 2), $t['recorded'], $t['failed'],
            $this->option('write') ? '' : ' DRY RUN -- re-run with --write to record.'));

        return ($refused || $t['failed']) ? self::FAILURE : self::SUCCESS;
    }
}
