<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Route;
use App\Models\RouteStage;
use App\Services\Routes\RouteEndpointStages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repair routes whose own endpoints were never written as stages.
 *
 * Every route created through routes/add before RouteEndpointStages existed is
 * unbookable: book_a_ride/routes matches a journey by joining route_stages
 * twice, so a route missing its first or last stop can never serve any pair.
 *
 * Only touches routes that are actually broken, and only ADDS stages — an
 * existing stop is never moved or deleted.
 */
class BackfillRouteEndpointStages extends Command
{
    protected $signature = 'routes:backfill-endpoint-stages
        {--route= : Only this route id}
        {--dry-run : Report what would change and write nothing}';

    protected $description = 'Add missing first/last stops so routes can be booked';

    public function handle(RouteEndpointStages $stages): int
    {
        $routes = Route::withoutGlobalScopes()
            ->whereNotNull('from_id')->whereNotNull('to_id')
            ->when($this->option('route'), fn ($q) => $q->whereKey((int) $this->option('route')))
            ->get(['id', 'name', 'from_id', 'to_id']);

        $broken = $routes->filter(function (Route $route): bool {
            $places = RouteStage::where('route_id', $route->id)->pluck('place_id')
                ->map(fn ($id) => (int) $id)->all();

            return ! in_array((int) $route->from_id, $places, true)
                || ! in_array((int) $route->to_id, $places, true);
        });

        $this->line(sprintf('%d of %d routes are missing an endpoint stage.', $broken->count(), $routes->count()));

        if ($broken->isEmpty()) {
            return self::SUCCESS;
        }

        $rows = [];
        $apply = function () use ($broken, $stages, &$rows): void {
            foreach ($broken as $route) {
                $added = $stages->ensure($route);
                $rows[] = [
                    $route->id,
                    $route->name ?? '(unnamed)',
                    $added,
                    RouteStage::where('route_id', $route->id)->count(),
                ];
            }
        };

        if ($this->option('dry-run')) {
            DB::beginTransaction();
            try {
                $apply();
            } finally {
                DB::rollBack();
            }
            $this->table(['route', 'name', 'stages added', 'stages now'], $rows);
            $this->info('Dry run — rolled back. Nothing was written.');

            return self::SUCCESS;
        }

        $apply();
        $this->table(['route', 'name', 'stages added', 'stages now'], $rows);
        $this->info('Backfill complete.');

        return self::SUCCESS;
    }
}
