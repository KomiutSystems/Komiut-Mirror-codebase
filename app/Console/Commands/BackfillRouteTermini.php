<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Route;
use App\Models\SaccoRoute;
use App\Services\Routes\RouteTerminusProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give every route a terminus at BOTH ends.
 *
 * A route has two termini: the bus departs from one and turns round at the
 * other. Provisioning only origins left NICCO with two termini for three
 * routes — Alsops, Thika Main Stage and Ngong had none — so a crew reaching
 * the far end had nowhere to queue for the return leg.
 *
 * Idempotent: a terminus already at a place is reused, never duplicated, and
 * the SACCO link is updateOrCreate.
 */
class BackfillRouteTermini extends Command
{
    protected $signature = 'termini:backfill
        {--sacco= : Only this SACCO id}
        {--user= : REQUIRED. Who is recorded as granting each link}
        {--dry-run : Report what would change and write nothing}';

    protected $description = 'Ensure every SACCO route has a terminus at both ends';

    public function handle(RouteTerminusProvisioner $termini): int
    {
        if ($this->option('user') === null) {
            $this->error('--user=<id> is required: sacco_termini.user_id records WHO granted the link.');

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
            ->get(['sacco_id', 'route_id']);

        $rows = [];

        $apply = function () use ($links, $termini, $userId, &$rows): void {
            foreach ($links as $link) {
                $route = Route::withoutGlobalScopes()->find($link->route_id);
                if ($route === null) {
                    continue;
                }

                foreach (['origin' => $route->from_id, 'destination' => $route->to_id] as $end => $placeId) {
                    if ($placeId === null) {
                        continue;
                    }

                    $before = DB::table('termini')->where('place_id', $placeId)->exists();
                    $terminus = $termini->ensureFor((int) $placeId, (int) $link->sacco_id, $userId);

                    $rows[] = [
                        $link->sacco_id,
                        $route->id,
                        $end,
                        $placeId,
                        $terminus?->name ?? '(place missing)',
                        $terminus === null ? 'SKIPPED' : ($before ? 'reused + linked' : 'created + linked'),
                    ];
                }
            }
        };

        if ($this->option('dry-run')) {
            DB::beginTransaction();
            try {
                $apply();
            } finally {
                DB::rollBack();
            }
            $this->table(['sacco', 'route', 'end', 'place', 'terminus', 'action'], $rows);
            $this->info('Dry run — rolled back. Nothing was written.');

            return self::SUCCESS;
        }

        DB::transaction($apply);
        $this->table(['sacco', 'route', 'end', 'place', 'terminus', 'action'], $rows);
        $this->info('Backfill complete.');

        return self::SUCCESS;
    }
}
