<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A point is worth something in shillings, and a ride costs what it costs.
 *
 * Until now a free ride cost `redemption_threshold` points FLAT: one figure for
 * a KES 60 hop and a KES 400 cross-county trip, for one seat and for four. So
 * "enough points for a ride" meant enough points for ANY ride with ANY number
 * of seats -- 50 points at NICCO (threshold 5) was ten bookings of unbounded
 * length and seat count. Found on 2026-09-12 on the first real booking.
 *
 * `point_value` is what one point pays for, in KES of fare. A booking costs
 * (fare x seats) / point_value points, a scanned ride costs the fare the
 * passenger names / point_value, and a money refund credits the same number of
 * points the fare would have cost. `redemption_threshold` keeps its meaning on
 * the passenger's card -- the points a standard ride takes, the goal they are
 * earning towards -- but it no longer prices anything.
 *
 * THE BACKFILL keeps every live program's existing promise at ONE STANDARD
 * FARE. The threshold was implicitly "one ride", and the ride it was set
 * against was a KES 150 Nairobi matatu trip -- the figure this was specified
 * with. So point_value = 150 / threshold: 5 points still buy a KES 150 ride at
 * NICCO, 50 points still buy one at the threshold-50 SACCOs. What changes is
 * that a KES 300 ride now costs twice that and a four-seat booking four times,
 * which is the point. A SACCO sets its own value in the dashboard; a program
 * with no value (threshold 0, nothing to derive from) cannot redeem until it
 * does, rather than falling back to the flat rule this retires.
 */
return new class extends Migration
{
    public const REFERENCE_FARE_KES = 150;

    public function up(): void
    {
        Schema::table('loyalty_programs', function (Blueprint $table) {
            // KES of fare one point pays for at redemption. Nullable: "not set"
            // must be expressible, and it refuses redemption rather than guessing.
            $table->double('point_value')->nullable()->after('redemption_threshold');
        });

        DB::table('loyalty_programs')
            ->whereNull('point_value')
            ->where('redemption_threshold', '>', 0)
            ->update(['point_value' => DB::raw(self::REFERENCE_FARE_KES.' / redemption_threshold')]);
    }

    public function down(): void
    {
        Schema::table('loyalty_programs', function (Blueprint $table) {
            $table->dropColumn('point_value');
        });
    }
};
