<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Place;
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
        {--all : Include routes no SACCO runs (see the note on orphans)}
        {--dry-run : Report what would change and write nothing}';

    protected $description = 'Add missing first/last stops so routes can be booked';

    public function handle(RouteEndpointStages $stages): int
    {
        // ONLY routes a SACCO actually runs, unless --all.
        //
        // 1,972 of prod's 1,973 routes are missing an endpoint stage, but 1,971
        // of those are unowned legacy imports: no sacco_id, no sacco_routes row,
        // no fare, no queue. Repairing them would make them match pickup/dropoff
        // searches and surface nearly two thousand unbookable routes in the
        // passenger app — a worse bug than the one being fixed.
        $routes = Route::withoutGlobalScopes()
            ->whereNotNull('from_id')->whereNotNull('to_id')
            ->when($this->option('route'), fn ($q) => $q->whereKey((int) $this->option('route')))
            ->when(! $this->option('route') && ! $this->option('all'), fn ($q) => $q
                ->where(fn ($w) => $w->whereNotNull('sacco_id')
                    ->orWhereIn('id', DB::table('sacco_routes')->where('status', true)->select('route_id'))))
            ->get(['id', 'name', 'from_id', 'to_id']);

        $broken = $routes->filter(function (Route $route): bool {
            $places = RouteStage::where('route_id', $route->id)->pluck('place_id')
                ->map(fn ($id) => (int) $id)->all();

            // A nameless route counts as broken too: the app titles a route card
            // with `name`, so a null one renders as an empty row the passenger
            // cannot tap. Prod route 1972 had stages and still looked missing.
            return ! in_array((int) $route->from_id, $places, true)
                || ! in_array((int) $route->to_id, $places, true)
                || ! filled($route->name);
        });

        $this->line(sprintf('%d of %d routes are missing an endpoint stage.', $broken->count(), $routes->count()));

        if ($broken->isEmpty()) {
            return self::SUCCESS;
        }

        $rows = [];
        $apply = function () use ($broken, $stages, &$rows): void {
            foreach ($broken as $route) {
                $added = $stages->ensure($route);
                $this->nameFromEndpoints($route);
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

    /** Title a nameless route after the stops it runs between. */
    private function nameFromEndpoints(Route $route): void
    {
        if (filled($route->name)) {
            return;
        }

        $from = Place::withoutGlobalScopes()->find($route->from_id);
        $to = Place::withoutGlobalScopes()->find($route->to_id);

        if ($from === null || $to === null) {
            return;
        }

        $route->forceFill(['name' => $from->name.' - '.$to->name])->save();
    }
}
