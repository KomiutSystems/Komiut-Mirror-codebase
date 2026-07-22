<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Brands\BrandRegistry;
use Illuminate\Console\Command;

/**
 * Runs the migrator once per brand, each against its own database.
 *
 * Every brand has its own database (see config/brands.php -> connection), so
 * migrations must run once per connection. This wraps Laravel's own `migrate`
 * (or `migrate:fresh`) and fans it out across brands, always passing --force so
 * it is safe to run non-interactively in production/CI:
 *
 *     php artisan brand:migrate                 # migrate every brand
 *     php artisan brand:migrate --brand=komiut  # just one brand
 *     php artisan brand:migrate --fresh --seed  # drop + re-migrate + seed each
 *     php artisan brand:migrate --pretend       # dump SQL without running it
 *
 * A single brand's failure does not abort the run — the remaining brands are
 * still migrated — but the command exits non-zero so CI/monitoring notices.
 */
final class BrandMigrate extends Command
{
    protected $signature = 'brand:migrate
        {--brand=* : Limit to specific brand keys (default: all)}
        {--fresh : Use migrate:fresh (drops all tables first)}
        {--seed : Run database seeders after migrating}
        {--pretend : Dump the SQL that would run without executing it}';

    protected $description = 'Run database migrations once per brand, each on its own database.';

    public function __construct(private readonly BrandRegistry $registry)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $brands = $this->registry->all();

        $only = $this->option('brand');
        if (! empty($only)) {
            $brands = array_intersect_key($brands, array_flip($only));
        }

        if (empty($brands)) {
            $this->error('No matching brands.');

            return self::FAILURE;
        }

        $migrateCommand = $this->option('fresh') ? 'migrate:fresh' : 'migrate';
        $exit = self::SUCCESS;

        foreach ($brands as $brand) {
            $this->newLine();
            $this->line("<info>==></info> Migrating <options=bold>{$brand->name}</> <comment>({$brand->connection})</comment>");

            // --force keeps every brand non-interactive so a production run never
            // stalls on the "run in production?" prompt.
            $arguments = [
                '--database' => $brand->connection,
                '--force' => true,
            ];

            if ($this->option('seed')) {
                $arguments['--seed'] = true;
            }

            if ($this->option('pretend')) {
                $arguments['--pretend'] = true;
            }

            $code = $this->call($migrateCommand, $arguments);

            if ($code !== self::SUCCESS) {
                // Keep going so one brand's failure doesn't block the others,
                // but surface a non-zero exit for CI/monitoring.
                $this->error("{$brand->name} ({$brand->connection}) migration failed with exit code {$code}.");
                $exit = $code;
            }
        }

        return $exit;
    }
}
