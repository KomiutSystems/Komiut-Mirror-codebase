<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * School bus tracking: a school is a SACCO of kind `school`, its buses carry a
 * follow code the school hands to parents, and a parent (an ordinary passenger
 * account) follows a bus to see it live and be told when it nears their stop.
 * See docs/school-bus-tracking.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saccos', function (Blueprint $table) {
            // 'sacco' for every existing row: nothing about a SACCO changes.
            $table->string('kind', 16)->default('sacco')->after('brand');
            $table->index('kind');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE saccos ADD CONSTRAINT saccos_kind_check CHECK (kind IN ('sacco', 'school'))");
        }

        Schema::table('vehicles', function (Blueprint $table) {
            // The shared secret a school gives its parents. Without it a parent
            // learns only that a plate belongs to a school -- never where the
            // bus is. NULL on every non-school bus.
            $table->string('follow_code', 16)->nullable()->unique()->after('coop_till');
        });

        Schema::create('vehicle_followers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            // Where the parent's child is picked up: a stop on the school's
            // route, or a pin of their own. Both optional; without one there
            // is simply no "approaching" alert.
            $table->foreignId('stop_place_id')->nullable()->constrained('places')->nullOnDelete();
            $table->decimal('stop_latitude', 10, 7)->nullable();
            $table->decimal('stop_longitude', 10, 7)->nullable();
            // One "bus approaching" alert per run, however many pings land
            // inside the radius.
            $table->unsignedBigInteger('last_alerted_queue_id')->nullable();
            $table->timestamp('last_alerted_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'vehicle_id']);
            $table->index('vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_followers');

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropUnique(['follow_code']);
            $table->dropColumn('follow_code');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE saccos DROP CONSTRAINT IF EXISTS saccos_kind_check');
        }

        Schema::table('saccos', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
