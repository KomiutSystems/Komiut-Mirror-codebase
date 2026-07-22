<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Sacco;
use App\Models\Vehicle;
use Illuminate\Console\Command;

/**
 * Assigns a brand to existing SACCOs (and their vehicles) that predate the
 * `brand` column and would otherwise be invisible to both apps.
 *
 * Usage:
 *   php artisan brand:backfill komiut                 # all unbranded saccos -> komiut
 *   php artisan brand:backfill safiri --sacco=5 --sacco=7   # only these saccos
 *   php artisan brand:backfill komiut --pretend       # show what would change
 *
 * Run once per brand. Runs unscoped, so it sees every row regardless of the
 * BrandScope.
 */
final class BrandBackfill extends Command
{
    protected $signature = 'brand:backfill
        {brand : the brand key to assign (must exist in config/brands.php)}
        {--sacco=* : limit to specific sacco ids (default: all unbranded)}
        {--pretend : report the changes without writing them}';

    protected $description = 'Backfill the brand column on existing SACCOs and their vehicles.';

    public function handle(): int
    {
        $brand = $this->argument('brand');

        if (! array_key_exists($brand, config('brands', []))) {
            $this->error("Unknown brand '{$brand}'. Known: " . implode(', ', array_keys(config('brands', []))));

            return self::FAILURE;
        }

        $query = Sacco::withoutGlobalScopes()->whereNull('brand');

        if ($ids = $this->option('sacco')) {
            $query->whereIn('id', $ids);
        }

        $saccos = $query->get();

        if ($saccos->isEmpty()) {
            $this->info('Nothing to backfill — no matching unbranded SACCOs.');

            return self::SUCCESS;
        }

        $vehicleCount = 0;

        foreach ($saccos as $sacco) {
            $vehicles = Vehicle::withoutGlobalScopes()->where('sacco_id', $sacco->id)->count();
            $vehicleCount += $vehicles;

            $this->line("  SACCO #{$sacco->id} <comment>{$sacco->name}</comment> -> <info>{$brand}</info> ({$vehicles} vehicles)");

            if (! $this->option('pretend')) {
                $sacco->forceFill(['brand' => $brand])->saveQuietly();
                Vehicle::withoutGlobalScopes()->where('sacco_id', $sacco->id)->update(['brand' => $brand]);
            }
        }

        $verb = $this->option('pretend') ? 'Would assign' : 'Assigned';
        $this->info("{$verb} '{$brand}' to {$saccos->count()} SACCO(s) and {$vehicleCount} vehicle(s).");

        return self::SUCCESS;
    }
}
