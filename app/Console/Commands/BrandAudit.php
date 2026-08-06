<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Sacco;
use App\Models\Vehicle;
use Illuminate\Console\Command;

/**
 * Reports brand-ownership anomalies that BrandScope would otherwise hide.
 *
 * Brand bugs are silent by construction: a vehicle in the wrong brand does not
 * error, it simply stops appearing in one app and starts appearing in another.
 * Nobody notices until a partner sees a bus that isn't theirs. This command is
 * the deliberate counterweight — it looks at the data unscoped and says out loud
 * what is unbranded or inconsistent.
 *
 *   php artisan brand:audit
 *
 * A SACCO spanning several brands is reported but is NOT an error: it is a real
 * production shape (NICCO MOVERS LIMITED runs Co-op and NCBA financed buses side
 * by side). It is listed so the split is a known, deliberate fact rather than a
 * surprise. Unbranded rows ARE a problem — BrandScope matches them to no brand,
 * so they are invisible to every app — and make the command exit non-zero so a
 * scheduled run can alert.
 */
final class BrandAudit extends Command
{
    protected $signature = 'brand:audit';

    protected $description = 'Report unbranded rows and SACCOs whose vehicles span multiple brands.';

    public function handle(): int
    {
        $problems = 0;

        $problems += $this->reportUnbranded();
        $this->newLine();
        $this->reportCrossBrandSaccos();

        if ($problems > 0) {
            $this->newLine();
            $this->error("{$problems} unbranded row(s) found — these are invisible to every brand.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('No unbranded rows.');

        return self::SUCCESS;
    }

    private function reportUnbranded(): int
    {
        $saccos = Sacco::withoutGlobalScopes()->whereNull('brand')->count();
        $vehicles = Vehicle::withoutGlobalScopes()->whereNull('brand')->count();

        $this->line('<comment>Unbranded rows</comment>');
        $this->line("  saccos:   {$saccos}");
        $this->line("  vehicles: {$vehicles}");

        return $saccos + $vehicles;
    }

    private function reportCrossBrandSaccos(): void
    {
        // Group unscoped so every brand's vehicles are counted, not just the active one.
        $rows = Vehicle::withoutGlobalScopes()
            ->selectRaw('sacco_id, brand, COUNT(*) as total')
            ->whereNotNull('brand')
            ->groupBy('sacco_id', 'brand')
            ->get()
            ->groupBy('sacco_id')
            ->filter(fn ($byBrand) => $byBrand->count() > 1);

        $this->line('<comment>SACCOs spanning multiple brands</comment>');

        if ($rows->isEmpty()) {
            $this->line('  none');

            return;
        }

        $names = Sacco::withoutGlobalScopes()
            ->whereIn('id', $rows->keys()->all())
            ->pluck('name', 'id');

        foreach ($rows as $saccoId => $byBrand) {
            $name = $names[$saccoId] ?? '(unknown)';
            $split = $byBrand->map(fn ($r): string => "{$r->brand}={$r->total}")->implode(', ');
            $this->line("  #{$saccoId} <info>{$name}</info> — {$split}");
        }

        $this->line('  (expected for SACCOs financed by more than one bank — vehicles.brand is authoritative)');
    }
}
