<?php

declare(strict_types=1);

use App\Models\RouteStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `sequence` is the travel-order ordinal of a stop along its route (1, 2, 3…).
 *
 * Seat reuse (SegmentSeatAvailability) needs a monotonic order to test whether
 * two ride segments overlap. It used `distance` — straight-line kilometres from
 * the origin — which mis-orders stops on a route that curves or doubles back and
 * can then double-sell a seat. `sequence` decouples that ordering from the crude
 * distance and is maintained in insertion order (the order the operator adds
 * stops, i.e. real travel order).
 *
 * Backfilled per route from the current distance ordering, so every existing
 * route keeps its exact present behaviour on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_stages', function (Blueprint $table) {
            $table->unsignedInteger('sequence')->default(0)->after('distance');
        });

        $this->backfillFromDistance();
    }

    public function down(): void
    {
        Schema::table('route_stages', function (Blueprint $table) {
            $table->dropColumn('sequence');
        });
    }

    /** Number each route's stops 1..n in their current distance order. */
    private function backfillFromDistance(): void
    {
        $routeIds = RouteStage::query()->distinct()->pluck('route_id');

        foreach ($routeIds as $routeId) {
            $stageIds = RouteStage::where('route_id', $routeId)
                ->orderBy('distance')
                ->orderBy('id')
                ->pluck('id');

            foreach ($stageIds as $position => $id) {
                RouteStage::whereKey($id)->update(['sequence' => $position + 1]);
            }
        }
    }
};
