<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Second slice of the legacy migration: seat layouts and vehicles.
 *
 * Runs after legacy:import-users — vehicles reference users and saccos, and
 * this command verifies those exist rather than creating placeholders.
 *
 * Legacy ids are preserved, as in the users slice, so the money tables that
 * follow (transactions, summaries, both keyed on vehicle_id) stay a straight
 * copy.
 *
 * BRAND. The new schema is brand-partitioned (BrandScope filters every query by
 * the active brand) but the legacy schema has no brand column. It has
 * `financier`, which is exactly the split: NCBA vehicles belong to Komiut,
 * coop-bank vehicles to Safiri. That mapping is applied here. A financier value
 * this command does not recognise is left null and reported rather than guessed
 * — a wrongly-branded vehicle is invisible on the portal that owns it, and
 * visible on the one that does not.
 *
 * `financier` itself is not carried over: the new schema has no such column,
 * brand supersedes it.
 */
class ImportLegacyVehicles extends Command
{
    protected $signature = 'legacy:import-vehicles
        {--file= : Path to the JSON export}
        {--dry-run : Report what would change and write nothing}
        {--replace-demo : Delete DemoSeeder vehicles first (they collide on id)}';

    protected $description = 'Import seats and vehicles from the legacy system';

    /** Legacy financier => new brand key (config/brands.php). */
    private const BRAND_BY_FINANCIER = [
        'NCBA' => 'komiut',
        'coop-bank' => 'safiri',
    ];

    public function handle(): int
    {
        $path = (string) $this->option('file');
        if ($path === '' || ! is_readable($path)) {
            $this->error('Pass a readable --file=<export.json>.');

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! isset($data['vehicles'])) {
            $this->error('Export does not look right (no "vehicles" key).');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $vehicles = $data['vehicles'];
        $seats = $data['seats'] ?? [];

        // Vehicles are meaningless without the rows they point at, and silently
        // importing orphans would leave the dashboard rendering blanks.
        $userIds = DB::table('users')->pluck('id')->flip();
        $saccoIds = DB::table('saccos')->pluck('id')->flip();
        if ($userIds->isEmpty()) {
            $this->error('users is empty — run legacy:import-users first.');

            return self::FAILURE;
        }

        $missingUser = $missingSacco = [];
        foreach ($vehicles as $v) {
            if ($v['user_id'] !== null && ! $userIds->has($v['user_id'])) {
                $missingUser[] = $v['plate'];
            }
            if ($v['sacco_id'] !== null && ! $saccoIds->has($v['sacco_id'])) {
                $missingSacco[] = $v['plate'];
            }
        }
        if ($missingUser !== [] || $missingSacco !== []) {
            $this->error(sprintf(
                '%d vehicle(s) reference a missing user, %d a missing sacco. Import those first.',
                count($missingUser), count($missingSacco),
            ));
            $this->line('  e.g. '.implode(', ', array_slice(array_merge($missingUser, $missingSacco), 0, 10)));

            return self::FAILURE;
        }

        // DemoSeeder vehicles occupy low ids and would collide with real ones.
        $legacyIds = array_map(fn ($v) => (int) $v['id'], $vehicles);
        $demo = DB::table('vehicles')->whereIn('id', $legacyIds)->count();
        if ($demo > 0 && ! $this->option('replace-demo')) {
            $this->error("vehicles already holds {$demo} row(s) with ids this import would overwrite.");
            $this->line('Those are almost certainly DemoSeeder fixtures. Re-run with --replace-demo');
            $this->line('to clear them, or clear them yourself if any are real.');

            return self::FAILURE;
        }

        $counts = ['seats' => 0, 'vehicles' => 0, 'demo_removed' => 0];
        $byBrand = [];
        $unknownFinancier = [];

        $apply = function () use ($seats, $vehicles, &$counts, &$byBrand, &$unknownFinancier) {
            if ($this->option('replace-demo')) {
                $counts['demo_removed'] = DB::table('vehicles')->delete();
            }

            foreach ($seats as $s) {
                DB::table('seats')->updateOrInsert(['id' => $s['id']], [
                    'name' => $s['name'],
                    'seats' => $s['seats'],
                    'rows' => $s['rows'],
                    'columns' => $s['columns'],
                    'status' => (bool) $s['status'],
                    'created_at' => $s['created_at'] ?? now(),
                    'updated_at' => now(),
                ]);
                $counts['seats']++;
            }

            foreach ($vehicles as $v) {
                $financier = $v['financier'] ?? null;
                $brand = self::BRAND_BY_FINANCIER[$financier] ?? null;
                if ($brand === null) {
                    $unknownFinancier[(string) $financier][] = $v['plate'];
                }
                $byBrand[$brand ?? '(none)'] = ($byBrand[$brand ?? '(none)'] ?? 0) + 1;

                DB::table('vehicles')->updateOrInsert(['id' => $v['id']], [
                    'plate' => $v['plate'],
                    'fleet_no' => $v['fleet_no'] ?? null,
                    'till_number' => $v['till_number'] ?? null,
                    'merchant_short_code' => $v['merchant_short_code'] ?? null,
                    // Which bank financed it, and therefore whose till its
                    // collections settle to. Kept alongside `brand` rather than
                    // folded into it: brand says which portal shows the vehicle,
                    // financier says who banks it, and the two banks reconcile
                    // separately.
                    'financier' => $financier,
                    'sacco_id' => $v['sacco_id'],
                    'user_id' => $v['user_id'],
                    'seat_id' => $v['seat_id'],
                    'mpesa_payment_setting_id' => $v['mpesa_payment_setting_id'] ?? null,
                    'status' => (bool) $v['status'],
                    'brand' => $brand,
                    'created_at' => $v['created_at'] ?? now(),
                    'updated_at' => now(),
                ]);
                $counts['vehicles']++;
            }

            // A sacco's brand follows its vehicles. Saccos whose fleet spans both
            // brands are left null and reported — that is a real business
            // question, not something to settle with a majority vote.
            $mixed = [];
            foreach (DB::table('vehicles')->select('sacco_id', 'brand')->whereNotNull('sacco_id')
                ->distinct()->get()->groupBy('sacco_id') as $saccoId => $rows) {
                $brands = $rows->pluck('brand')->filter()->unique()->values();
                if ($brands->count() === 1) {
                    DB::table('saccos')->where('id', $saccoId)->update(['brand' => $brands[0]]);
                } elseif ($brands->count() > 1) {
                    $mixed[] = $saccoId;
                }
            }
            $counts['saccos_mixed_brand'] = count($mixed);
        };

        if ($dryRun) {
            DB::beginTransaction();
            try {
                $apply();
            } finally {
                DB::rollBack();
            }
            $this->report($counts, $byBrand, $unknownFinancier);
            $this->info('Dry run — rolled back. Nothing was written.');

            return self::SUCCESS;
        }

        DB::transaction($apply);
        $this->resyncSequences(['seats', 'vehicles']);
        $this->report($counts, $byBrand, $unknownFinancier);
        $this->info('Import complete.');

        return self::SUCCESS;
    }

    private function resyncSequences(array $tables): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($tables as $t) {
            DB::statement("SELECT setval(pg_get_serial_sequence('{$t}', 'id'), COALESCE((SELECT MAX(id) FROM {$t}), 1))");
            $this->line("  sequence re-synced: {$t}");
        }
    }

    private function report(array $counts, array $byBrand, array $unknownFinancier): void
    {
        $this->newLine();
        $this->table(['what', 'rows'], collect($counts)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->table(['brand', 'vehicles'], collect($byBrand)->map(fn ($v, $k) => [$k, $v])->values()->all());

        foreach ($unknownFinancier as $financier => $plates) {
            $this->warn(sprintf(
                'Unrecognised financier "%s" on %d vehicle(s) — brand left NULL: %s',
                $financier, count($plates), implode(', ', array_slice($plates, 0, 10)),
            ));
            $this->line('  A null brand is filtered out by BrandScope, so these will not appear on either portal.');
        }
    }
}
