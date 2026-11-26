<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Team\Kpis\KpiCalculator;
use Illuminate\Console\Command;

/**
 * The nightly reading of the ops board's level KPIs.
 *
 * "Tills registered" is a share of the fleet as it stands, not a sum over
 * days, so last week's value cannot be recomputed later: nothing records what
 * each bus's status was on Sunday night. This writes it down once a day, and
 * the board's "previous" reads it back. Idempotent: a re-run on the same
 * business day replaces that day's row.
 */
class TeamKpiSnapshotCommand extends Command
{
    protected $signature = 'team:snapshot-kpis';

    protected $description = 'Record today\'s level KPIs (tills registered) for the ops board\'s trend';

    public function handle(KpiCalculator $kpis): int
    {
        $snapshot = $kpis->snapshot();

        $this->info($snapshot === null
            ? 'No tills to measure; nothing recorded.'
            : "tills_registered on {$snapshot->date->toDateString()}: {$snapshot->value}%");

        return self::SUCCESS;
    }
}
