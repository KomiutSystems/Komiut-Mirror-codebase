<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cancelled booking says why.
 *
 * `status = false` was the whole story: the passenger's list showed "failed"
 * for a hold that expired unpaid, for a booking they cancelled themselves, and
 * for a paid seat the crew marked NOT BOARDED and refunded. The reason existed
 * -- BookingCancellationReason rides on the BookingCancelled event and decides
 * which notification goes out -- but it was never written down, so the moment
 * the notification was sent the distinction was gone. The passenger's "My
 * bookings" screen cannot say "Not boarded -- 5 points refunded" without it.
 *
 * Every path that cancels writes it: the two unpaid sweeps (expired), the
 * crew's no-show and the trip-end sweep (no_show), the passenger's own cancel
 * (cancelled), and the model hook fills `cancelled` for any Eloquent save that
 * flips status without saying more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('cancellation_reason', 32)->nullable()->after('status');
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['cancellation_reason', 'cancelled_at']);
        });
    }
};
