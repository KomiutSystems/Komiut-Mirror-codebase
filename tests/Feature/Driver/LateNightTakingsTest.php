<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Models\Transaction;
use App\Support\BusinessDay;
use Illuminate\Support\Carbon;
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

        Transaction::withoutGlobalScopes()->create([
            'vehicle_id' => $world['vehicle']->id,
            'amount' => 1,
            // Exactly what C2bPaymentRecorder writes: M-Pesa's EAT clock, raw.
            'trans_date' => $this->afterMidnightInNairobi()->format('Y-m-d H:i:s'),
        ]);

        // 21:38 UTC on the 30th is 00:38 EAT on the 31st -- inside the business
        // day that opened at 03:00 EAT on the 30th.
        Carbon::setTestNow(Carbon::parse('2026-08-30 21:38:00', 'UTC'));
        [$from, $to] = BusinessDay::windowFor();
        Carbon::setTestNow();

        $sum = fn (Carbon $a, Carbon $b) => (float) Transaction::withoutGlobalScopes()
            ->where('vehicle_id', $world['vehicle']->id)
            ->where('trans_date', '>=', $a)
            ->where('trans_date', '<', $b)
            ->sum('amount');

        // The bug: bound as UTC, the 03:00 boundary reads "00:00" and the
        // shilling falls outside the day it belongs to.
        $this->assertSame(0.0, $sum($from, $to), 'the UTC-bound window is what lost it');

        // The fix: bound as Nairobi, which is what the column actually stores.
        $this->assertSame(1.0, $sum(BusinessDay::forLocalColumn($from), BusinessDay::forLocalColumn($to)));
    }
}
