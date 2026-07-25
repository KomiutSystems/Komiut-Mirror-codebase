<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `booking_type` records whether a booking was made at the terminus ('route')
 * or flagged down while the matatu was broadcasting on the road
 * ('pick_as_you_go'). It is set at booking time from the queue's status and
 * drives whether the driver app shows a live passenger map. Existing bookings
 * default to 'route' — the only mode that existed before pick-as-you-go.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('booking_type')->default('route')->after('to_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('booking_type');
        });
    }
};
