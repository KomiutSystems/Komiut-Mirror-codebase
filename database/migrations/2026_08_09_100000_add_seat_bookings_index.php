<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `seat_bookings` had nothing but its primary key.
 *
 * Laravel's foreignIdFor() creates the column, not an index, and on PostgreSQL a
 * foreign key does not imply one either — so every lookup by booking_id was a
 * sequential scan. That is the only way this table is ever read:
 *
 *   SeatBooking::where('booking_id', ...)        seat map for one booking
 *   SeatBooking::whereIn('booking_id', ...)      CheckPassengerPayments, every
 *                                                2 minutes, releasing seats for
 *                                                unpaid bookings
 *
 * The scheduled sweep is the one that matters: it runs forever, and its cost
 * grows with the whole table rather than with the bookings it is releasing.
 *
 * (queue_id, status) supports the seat-availability read — "which seats on this
 * queue are still free" — which is on the booking path a passenger waits on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_bookings', function (Blueprint $table) {
            $table->index('booking_id', 'seat_bookings_booking_id_index');
        });

        // Guarded: this column set has drifted between environments, and a
        // migration that assumes a column exists takes the whole deploy down.
        if (Schema::hasColumn('seat_bookings', 'queue_id') && Schema::hasColumn('seat_bookings', 'status')) {
            Schema::table('seat_bookings', function (Blueprint $table) {
                $table->index(['queue_id', 'status'], 'seat_bookings_queue_id_status_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('seat_bookings', function (Blueprint $table) {
            $table->dropIndex('seat_bookings_booking_id_index');
        });

        if (Schema::hasColumn('seat_bookings', 'queue_id') && Schema::hasColumn('seat_bookings', 'status')) {
            Schema::table('seat_bookings', function (Blueprint $table) {
                $table->dropIndex('seat_bookings_queue_id_status_index');
            });
        }
    }
};
