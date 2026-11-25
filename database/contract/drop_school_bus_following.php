<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Following is gone: a passenger saves a bus on their phone, the server keeps
 * no list of who saved what, and a saved bus is found by its plate alone -- no
 * follow code. So the follower rows and the per-bus code go.
 * See docs/school-bus-tracking.md ("Saved buses").
 *
 * saccos.kind and vehicle_locations.speed_kmh stay: schools are still schools,
 * and the app works out its own arrival estimate from the bus's speed.
 *
 * down() puts both back exactly as 2026_11_26_100000 made them (empty: the
 * rows and codes are not recoverable, and nothing reads them any more).
 *
 * NOT A MIGRATION YET -- this is the CONTRACT half of expand/contract, parked
 * outside database/migrations so `migrate` does not run it. The deploy
 * migrates before it rolls the instances, and the release before saved buses
 * still reads both the table and the column (school/buses, the school-bus
 * channel, the follower alert on every school-bus ping); dropping them under
 * it breaks those paths for the length of the roll, or for good if the roll
 * fails (.github/workflows/deploy.yml, "expand now, contract in a later
 * release"). Ship it in the release AFTER saved buses: move it to
 * database/migrations/<that day>_drop_school_bus_following.php, and remove
 * 'follow_code' from Vehicle::$hidden in the same change.
 *
 * Each half sets a short lock_timeout. Dropping vehicle_followers drops the
 * foreign-key triggers on users, vehicles and places, which takes an ACCESS
 * EXCLUSIVE lock on each, held to commit (one transaction): queued behind a
 * long reader, it would stall every request that reads users. Better the
 * migrate fails fast and is retried. SET LOCAL ends with the transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        Schema::dropIfExists('vehicle_followers');

        if (Schema::hasColumn('vehicles', 'follow_code')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->dropUnique(['follow_code']);
                $table->dropColumn('follow_code');
            });
        }
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        if (! Schema::hasColumn('vehicles', 'follow_code')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->string('follow_code', 16)->nullable()->unique()->after('coop_till');
            });
        }

        if (! Schema::hasTable('vehicle_followers')) {
            Schema::create('vehicle_followers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
                $table->foreignId('stop_place_id')->nullable()->constrained('places')->nullOnDelete();
                $table->decimal('stop_latitude', 10, 7)->nullable();
                $table->decimal('stop_longitude', 10, 7)->nullable();
                $table->unsignedBigInteger('last_alerted_queue_id')->nullable();
                $table->timestamp('last_alerted_at')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'vehicle_id']);
                $table->index('vehicle_id');
            });
        }
    }
};
