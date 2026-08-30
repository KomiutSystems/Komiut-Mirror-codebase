<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Enums\UserType;
use App\Models\Transaction;
use App\Models\VehicleUser;
use App\Support\BusinessDay;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Money taken between midnight and 03:00 belongs to the day that is ending.
 *
 * `transactions.trans_date` is written straight from M-Pesa's `TransTime`
 * (C2bPaymentRecorder), which is Nairobi wall-clock — NOT UTC, unlike every
 * Laravel timestamp beside it. The business-day window was computed correctly
 * in Nairobi and then converted to UTC before binding, so the 03:00 EAT
 * boundary reached the database as the string "00:00:00".
 *
 * The window therefore collapsed to the EAT calendar day, and every payment
 * between 00:00 and 03:00 fell outside the business day it belongs to. A driver
 * on a late run watched a real shilling land and saw "TODAY KES 0" — which is
 * how this was found.
 */
final class LateNightTakingsTest extends QueueTestCase
{
    /** 00:36 Nairobi — inside the business day that began at 03:00 yesterday. */
    private function afterMidnightInNairobi(): Carbon
    {
        return Carbon::parse('2026-08-31 00:36:54', BusinessDay::TIMEZONE);
    }

    #[Test]
    public function the_window_reaches_a_column_that_stores_nairobi_time(): void
    {
        // 21:38 UTC on the 30th is 00:38 EAT on the 31st.
        Carbon::setTestNow(Carbon::parse('2026-08-30 21:38:00', 'UTC'));

        [$from, $to] = BusinessDay::windowFor();

        // Bound as UTC this reads 00:00 — three hours early, and the bug.
        $this->assertSame('2026-08-30 00:00:00', $from->format('Y-m-d H:i:s'));

        // Bound for an EAT column it reads 03:00, the real boundary.
        $this->assertSame('2026-08-30 03:00:00', BusinessDay::forLocalColumn($from)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-31 03:00:00', BusinessDay::forLocalColumn($to)->format('Y-m-d H:i:s'));

        // Same instant either way — only the representation moves.
        $this->assertTrue($from->equalTo(BusinessDay::forLocalColumn($from)));

        Carbon::setTestNow();
    }

    #[Test]
    public function a_payment_at_half_past_midnight_counts_toward_todays_takings(): void
    {
        $world = $this->makeWorld();
        $driver = $this->makeUser([], $world['sacco']);
        $driver->forceFill(['type' => UserType::Driver])->save();

        VehicleUser::create([
            'user_id' => $driver->id,
            'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id,
            'status' => true,
            'start_date' => now()->subYear(),
        ]);

        Transaction::withoutGlobalScopes()->create([
            'vehicle_id' => $world['vehicle']->id,
            'amount' => 1,
            // Exactly what C2bPaymentRecorder writes: M-Pesa's EAT clock, raw.
            'trans_date' => $this->afterMidnightInNairobi()->format('Y-m-d H:i:s'),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-30 21:38:00', 'UTC'));
        Sanctum::actingAs($driver);

        $home = $this->getJson('/api/auth/driver/home')->assertOk()->json();

        Carbon::setTestNow();

        // Before the fix this was 0: the shilling was real, attributed and
        // invisible, because the 03:00 boundary bound as "00:00".
        $this->assertSame(1.0, (float) data_get($home, 'today.total'));

        // The feed is vehicle-scoped and not date-filtered, so it showed the
        // payment even while the total said zero — the two disagreed.
        $this->assertSame(1, count(data_get($home, 'recent_transactions', [])));
    }
}
