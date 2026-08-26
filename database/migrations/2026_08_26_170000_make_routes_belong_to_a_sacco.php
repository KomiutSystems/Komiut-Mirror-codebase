<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give `routes` an owner.
 *
 * WHY. `routes` was a global catalogue with no sacco_id and no scope, on the
 * theory that a corridor (Nairobi CBD -> Thika) is a physical fact several
 * SACCOs share. That theory is not what the platform does, and it cost us:
 *
 *   - PROVEN cross-tenant writes. routes/add takes an `id` and does
 *     Route::findOrFail($id) behind a permission check with NO ownership check,
 *     so any SACCO Admin could rewrite ANY route's name, from_id, to_id and
 *     status. Everything else — sacco_routes, route_fares, queues — points at
 *     routes.id, so re-destinating a row silently re-pointed another SACCO's
 *     fares and live queues without touching a single row of theirs. The same
 *     shape existed on route_stages and route_stages/coords.
 *   - The boundary already contradicted itself: ResourceStateController refuses
 *     a SACCO admin the right to set routes.status, while routes/add let the
 *     same caller set that same column on that same row.
 *
 * And the shared model was never actually used. On production at the time of
 * writing: 1,972 routes, of which 1,971 have no SACCO pointing at them at all,
 * every single one has a NULL name, and ZERO routes are referenced by more than
 * one SACCO. The sharing this design paid for has never once happened.
 *
 * NULLABLE, not NOT NULL. The 1,971 orphans from the 2026-08-07 import have no
 * owner to give them and deleting them here would be a data loss decision taken
 * inside a migration. They keep sacco_id NULL, which under SaccoScope makes them
 * invisible to every SACCO admin — which is the desired outcome anyway, since a
 * picker listing 1,971 indistinguishable unnamed routes is unusable.
 *
 * The backfill claims the one route that IS owned, via the sacco_routes pivot
 * that has been carrying ownership implicitly all along.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('routes', 'sacco_id')) {
            Schema::table('routes', function (Blueprint $table): void {
                // No FK constraint: `saccos` is brand-partitioned and the orphan
                // rows must be allowed to sit ownerless. The index is what this
                // is for — every scoped query filters on it.
                $table->unsignedBigInteger('sacco_id')->nullable()->after('id');
                $table->index('sacco_id', 'routes_sacco_id_index');
            });
        }

        // Adopt each route into the SACCO that already linked to it. Where two
        // SACCOs somehow share one (zero cases in production, but the query must
        // not silently pick a winner), MIN(sacco_id) is deterministic rather
        // than arbitrary — and the duplicate is reported by the audit query in
        // the route-ownership test rather than papered over here.
        DB::statement(<<<'SQL'
            UPDATE routes r
               SET sacco_id = s.sacco_id
              FROM (
                    SELECT route_id, MIN(sacco_id) AS sacco_id
                      FROM sacco_routes
                     GROUP BY route_id
                   ) s
             WHERE s.route_id = r.id
               AND r.sacco_id IS NULL
        SQL);
    }

    public function down(): void
    {
        if (Schema::hasColumn('routes', 'sacco_id')) {
            Schema::table('routes', function (Blueprint $table): void {
                $table->dropIndex('routes_sacco_id_index');
                $table->dropColumn('sacco_id');
            });
        }
    }
};
