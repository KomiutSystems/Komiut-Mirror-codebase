<?php

declare(strict_types=1);

use App\Services\Seats\SeatMapGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Give the five seat layouts their seats.
 *
 * `seat_arrangements` was empty — zero rows platform-wide — while every booking
 * resolves the ids a passenger picks through it. So the seat lookup returned
 * null for every seat on every bus and addBooking refused with a 400 before
 * writing anything, which is why `bookings` has never held a single row.
 *
 * This is reference data, not user data: it is derived entirely from the
 * layout's own `seats`, `rows` and `columns`, and there is no API that creates a
 * layout, so the five that exist are the five there will be until someone ships
 * a seeder. That makes it a migration rather than a one-off script — it belongs
 * with the deploy, it runs exactly once, and a fresh environment gets a bookable
 * platform instead of one that 400s on the first passenger.
 *
 * Idempotent by way of the generator: a layout that already has seats is left
 * untouched. That matters more than it looks. Arrangement ids are stored in
 * seat_bookings, so regenerating a map under a live booking would move a
 * passenger to a different seat or leave their booking pointing at one that no
 * longer exists. Re-running this is safe; it simply does nothing.
 *
 * down() is deliberately empty. Deleting seats that bookings already reference
 * to undo a migration would take real reservations with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seats') || ! Schema::hasTable('seat_arrangements')) {
            return;
        }

        app(SeatMapGenerator::class)->generateMissing();
    }

    public function down(): void
    {
        // Intentionally empty — see the class docblock.
    }
};
