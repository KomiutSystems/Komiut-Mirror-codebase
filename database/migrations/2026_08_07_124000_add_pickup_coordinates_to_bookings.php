<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the passenger is actually standing, for roadside (pick-as-you-go) pickups.
 *
 * A booking records `from_id` — a Place, i.e. a stop on the route. That is the
 * right model for a terminus booking, where the passenger really is at a stop.
 * It is the wrong model for a vehicle flagged down mid-route: the passenger is
 * beside the road, not at a stage, and BroadcastReservationController has to
 * snap their GPS to the nearest stop before the dropoff just to produce a
 * `from_id` at all. That snap is lossy and the original point was discarded.
 *
 * On a dense Nairobi corridor the nearest stage is close enough. On a long rural
 * leg where stages are kilometres apart, "Ruiru" tells the driver almost nothing
 * about where to actually stop — and stopping in the right place is the entire
 * product promise of pick-as-you-go.
 *
 * Nullable on purpose: terminus bookings through book_a_ride/booking/add have no
 * roadside point and must keep working untouched. A NULL here means "this
 * booking was made at a stop", which is real information, not missing data.
 *
 * Deliberately NOT indexed. Nothing searches bookings by coordinate; these are
 * read back per-booking on the driver's manifest, already reached by queue_id.
 * An index would only tax every booking insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->double('pickup_latitude')->nullable()->after('to_id');
            $table->double('pickup_longitude')->nullable()->after('pickup_latitude');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn(['pickup_latitude', 'pickup_longitude']);
        });
    }
};
