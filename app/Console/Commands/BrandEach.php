<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Brands\BrandContext;
use App\Brands\BrandRegistry;
use Illuminate\Console\Command;

/**
 * Runs another artisan command once per brand, each against its own database.
 *
 * Console and scheduled work has no request and no Context, so nothing resolves
 * a brand automatically. Scheduled jobs that touch brand data must fan out
 * through this wrapper:
 *
 *     $schedule->command('brand:each app:generate-user-points')->everyTenMinutes();
 *
 * which runs `app:generate-user-points` against komiut, then against safiri.
 */
final class BrandEach extends Command
{
    protected $signature = 'brand:each
        {command : The artisan command to run for each brand}
        {--brand=* : Limit to specific brand keys (default: all)}';

    protected $description = 'Run an artisan command once per brand, each on its own database.';

    public function handle(BrandRegistry $registry, BrandContext $context): int
    {
        $brands = $registry->all();

        $only = $this->option('brand');
        if (! empty($only)) {
            $brands = array_intersect_key($brands, array_flip($only));
        }

        if (empty($brands)) {
            $this->error('No matching brands.');

            return self::FAILURE;
        }

        $inner = $this->argument('command');
        $exit = self::SUCCESS;

        foreach ($brands as $brand) {
            $this->line("<info>→</info> {$brand->name} <comment>({$brand->connection})</comment>");

            $context->apply($brand);

            $code = $this->call($inner);

            if ($code !== self::SUCCESS) {
                // Keep going so one brand's failure doesn't starve the others,
                // but surface a non-zero exit for the scheduler/monitoring.
                $exit = $code;
            }
        }

        return $exit;
    }
}
