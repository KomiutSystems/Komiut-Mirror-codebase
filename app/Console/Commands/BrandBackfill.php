<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Sacco;
use App\Models\Vehicle;
use App\Services\Sql\PlateSql;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Assigns a brand to existing SACCOs (and their vehicles) that predate the
 * `brand` column and would otherwise be invisible to both apps.
 *
 * Two modes:
 *
 *   SACCO mode — the bulk pass. Brands whole unbranded SACCOs and their fleets.
 *     php artisan brand:backfill komiut                        # all unbranded saccos
 *     php artisan brand:backfill safiri --sacco=5 --sacco=7    # only these saccos
 *     php artisan brand:backfill komiut --pretend              # show, don't write
 *
 *   PLATE mode — the correction pass. Brands individual vehicles by number
 *   plate, leaving their SACCO untouched. Needed because a SACCO may span both
 *   brands (see below), so the bulk pass alone cannot express reality.
 *     php artisan brand:backfill safiri --plate="KDR 027C" --plate=KDW865G
 *     php artisan brand:backfill safiri --plates-file=storage/coop-plates.txt
 *
 * A SACCO IS NOT NECESSARILY SINGLE-BRAND. In production, NICCO MOVERS LIMITED
 * holds 54 Co-op-financed vehicles alongside 126 NCBA ones. `vehicles.brand` is
 * therefore the authoritative value for scoping; `saccos.brand` records only the
 * SACCO's primary brand. Never "repair" vehicle brands by copying the SACCO's —
 * that silently moves buses between brands, and BrandScope then hides the damage.
 *
 * Plate mode deliberately OVERWRITES an existing brand: the normal sequence is a
 * bulk SACCO pass followed by a plate pass correcting the minority. Plates are
 * matched with spaces, dashes and dots stripped and upper-cased on both sides,
 * because operators supply them in inconsistent formats ("KDW865G", "KDW 865G").
 * Any plate that matches nothing is reported and the command fails — an
 * unmatched plate is usually a typo, and silently skipping it would leave a bus
 * in the wrong brand.
 *
 * Runs unscoped, so it sees every row regardless of the BrandScope.
 */
final class BrandBackfill extends Command
{
    protected $signature = 'brand:backfill
        {brand : the brand key to assign (must exist in config/brands.php)}
        {--sacco=* : limit to specific sacco ids (default: all unbranded)}
        {--plate=* : assign to individual vehicles by plate, leaving SACCOs untouched}
        {--plates-file= : path to a file of plates, one per line (blank lines ignored)}
        {--pretend : report the changes without writing them}';

    protected $description = 'Backfill the brand column on existing SACCOs and their vehicles.';

    /**
     * Strip formatting so "KDW865G-NM" and "KDW 865G" compare equal.
     *
     * This command had the more forgiving definition of the two: it stripped
     * hyphens and dots while the driver-login lookup stripped only spaces, so a
     * plate this could reconcile was a plate a driver could not sign in with.
     * Both now go through PlateSql.
     */
    private static function normaliseSql(): string
    {
        return PlateSql::normaliseColumn('plate');
    }

    public function handle(): int
    {
        $brand = (string) $this->argument('brand');

        if (! array_key_exists($brand, config('brands', []))) {
            $this->error("Unknown brand '{$brand}'. Known: ".implode(', ', array_keys(config('brands', []))));

            return self::FAILURE;
        }

        $plates = $this->collectPlates();

        if ($plates === null) {
            return self::FAILURE;
        }

        return $plates === []
            ? $this->backfillSaccos($brand)
            : $this->backfillPlates($brand, $plates);
    }

    /**
     * Gather plates from --plate and --plates-file.
     *
     * @return list<string>|null normalised plates, or null if the file is unreadable
     */
    private function collectPlates(): ?array
    {
        $raw = $this->option('plate');

        if ($file = $this->option('plates-file')) {
            if (! is_readable($file)) {
                $this->error("Cannot read plates file: {$file}");

                return null;
            }

            $raw = array_merge($raw, file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        }

        $normalised = [];
        foreach ($raw as $plate) {
            $plate = $this->normalise($plate);
            if ($plate !== '' && ! in_array($plate, $normalised, true)) {
                $normalised[] = $plate;
            }
        }

        return $normalised;
    }

    private function normalise(string $plate): string
    {
        return PlateSql::normalise($plate);
    }

    /** The bulk pass: whole SACCOs and their fleets. */
    private function backfillSaccos(string $brand): int
    {
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

    /**
     * The correction pass: individual vehicles, SACCO left alone.
     *
     * @param  list<string>  $plates
     */
    private function backfillPlates(string $brand, array $plates): int
    {
        $matched = Vehicle::withoutGlobalScopes()
            ->whereIn(DB::raw(self::normaliseSql()), $plates)
            ->get(['id', 'plate', 'sacco_id', 'brand']);

        $found = $matched->map(fn (Vehicle $v): string => $this->normalise((string) $v->plate))->all();
        $missing = array_values(array_diff($plates, $found));

        foreach ($matched as $vehicle) {
            $from = $vehicle->brand ?? 'unbranded';
            $this->line("  {$vehicle->plate} <comment>{$from}</comment> -> <info>{$brand}</info>");
        }

        if ($missing !== []) {
            $this->newLine();
            $this->error('These plates matched no vehicle (likely typos — nothing was written):');
            foreach ($missing as $plate) {
                $this->line("  <error>{$plate}</error>");
            }

            return self::FAILURE;
        }

        if (! $this->option('pretend')) {
            Vehicle::withoutGlobalScopes()
                ->whereIn('id', $matched->pluck('id')->all())
                ->update(['brand' => $brand]);
        }

        $verb = $this->option('pretend') ? 'Would assign' : 'Assigned';
        $this->info("{$verb} '{$brand}' to {$matched->count()} vehicle(s) by plate. SACCOs untouched.");

        return self::SUCCESS;
    }
}
