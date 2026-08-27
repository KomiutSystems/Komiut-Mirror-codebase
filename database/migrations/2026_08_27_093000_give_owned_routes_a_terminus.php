<?php

declare(strict_types=1);

use App\Services\Routes\RouteTerminusProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give the routes a SACCO already owns somewhere to depart from.
 *
 * The route builder now creates a terminus alongside the route, but the routes
 * built before it did not get one — and a route with no terminus at its origin
 * can never carry a single trip. `queues.terminus_id` is NOT NULL and both the
 * driver and the dispatcher check that the terminus's place IS the route
 * origin, so it fails as a 422 rather than degrading. No queue, no booking, and
 * the route is invisible to every passenger with nothing to explain why.
 *
 * SCOPED TO OWNED ROUTES ONLY. `routes` holds 1,973 rows and just 2 of them
 * carry a sacco_id — the two NICCO built through the dashboard. The other 1,971
 * are the legacy catalogue: nobody runs them, no sacco_routes row points at
 * them, and minting 1,971 terminus rows for stages no bus departs from would
 * turn a 41-row table into junk. If one of them is ever claimed by a SACCO, it
 * will get its terminus from the builder like any other.
 *
 * Idempotent through the provisioner: an existing terminus at that place is
 * reused and the SACCO link is an updateOrCreate, so re-running changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['routes', 'termini', 'sacco_termini', 'places'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        if (! Schema::hasColumn('routes', 'sacco_id')) {
            return;
        }

        $provisioner = app(RouteTerminusProvisioner::class);

        $routes = DB::table('routes')
            ->whereNotNull('sacco_id')
            ->whereNotNull('from_id')
            ->get(['id', 'sacco_id', 'from_id']);

        foreach ($routes as $route) {
            // `sacco_termini.user_id` is NOT NULL and records who granted the
            // SACCO its right to work out of the stage. There is no acting user
            // in a migration, so attribute it to whoever registered the route
            // for this SACCO — the closest true answer available — and fall
            // back to any member of that SACCO.
            $userId = DB::table('sacco_routes')
                ->where('route_id', $route->id)
                ->value('user_id')
                ?? DB::table('users')->where('sacco_id', $route->sacco_id)->min('id');

            if ($userId === null) {
                // A SACCO with no members at all. Nothing to attribute the link
                // to, and nobody to run the route either — skip rather than
                // invent an owner.
                continue;
            }

            $provisioner->ensureFor(
                (int) $route->from_id,
                (int) $route->sacco_id,
                (int) $userId
            );
        }
    }

    public function down(): void
    {
        // Intentionally empty. Removing a terminus would strand any queue that
        // has since been created against it, and termini are shared across
        // SACCOs — deleting one to reverse a migration would take other
        // operators' stages with it.
    }
};
