<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Route;
use App\Models\RouteStage;
use App\Models\SaccoRoute;
use App\Services\Routes\ReturnRouteBuilder;
use App\Services\Routes\RouteTerminusProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give every one-way route its way home.
 *
 * A matatu run is there-and-back, but a route row holds one direction, and both
 * queue writers require the terminus to BE the route's origin. So a bus at the
 * far end cannot queue for the return until that direction exists as its own
 * route — which is why provisioning termini at destinations achieved nothing on
 * its own.
 */
class CreateReturnRoutes extends Command
{
    protected $signature = 'routes:create-return
        {--sacco= : Only this SACCO id}
        {--route= : Only this route id}
        {--user= : REQUIRED. Who is recorded as creating the return leg}
        {--dry-run : Report what would change and write nothing}';

    protected $description = 'Create the reverse of each route, mirroring its stops and fares';

    public function handle(ReturnRouteBuilder $builder, RouteTerminusProvisioner $termini): int
    {
        if ($this->option('user') === null) {
            $this->error('--user=<id> is required: it records who created the return leg.');

            return self::FAILURE;
        }

        $userId = (int) $this->option('user');
        if (! DB::table('users')->where('id', $userId)->exists()) {
            $this->error("--user={$userId} is not a user.");

            return self::FAILURE;
        }

        $links = SaccoRoute::withoutGlobalScopes()
            ->where('status', true)
            ->when($this->option('sacco'), fn ($q) => $q->where('sacco_id', (int) $this->option('sacco')))
            ->when($this->option('route'), fn ($q) => $q->where('route_id', (int) $this->option('route')))
            ->get(['sacco_id', 'route_id']);

        $rows = [];

        $apply = function () use ($links, $builder, $termini, $userId, &$rows): void {
            foreach ($links as $link) {
                $route = Route::withoutGlobalScopes()->with(['from', 'to'])->find($link->route_id);
                if ($route === null) {
                    continue;
                }

                // Skip a route that IS somebody's return leg already, or we would
                // ping-pong: A->B creates B->A, then B->A creates A->B.
                $isReverseOfAnother = Route::withoutGlobalScopes()
                    ->where('sacco_id', $link->sacco_id)
                    ->where('from_id', $route->to_id)
                    ->where('to_id', $route->from_id)
                    ->exists();

                $before = $isReverseOfAnother;
                $return = $builder->ensureFor($route, (int) $link->sacco_id, $userId);

                if ($return === null) {
                    $rows[] = [$route->id, $route->name ?? '-', '-', 'SKIPPED (needs 2+ stops)'];

                    continue;
                }

                // The far end must be a stage the SACCO may work out of, or the
                // return leg is as unqueueable as the outbound was.
                $termini->ensureFor((int) $return->from_id, (int) $link->sacco_id, $userId);

                $rows[] = [
                    $route->id,
                    $route->name ?? '-',
                    $return->id.'  '.($return->name ?? '-'),
                    $before ? 'already existed' : 'created ('.RouteStage::where('route_id', $return->id)->count().' stops)',
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
            $this->table(['outbound', 'name', 'return leg', 'action'], $rows);
            $this->info('Dry run — rolled back. Nothing was written.');

            return self::SUCCESS;
        }

        DB::transaction($apply);
        $this->table(['outbound', 'name', 'return leg', 'action'], $rows);
        $this->info('Return legs complete.');

        return self::SUCCESS;
    }
}
