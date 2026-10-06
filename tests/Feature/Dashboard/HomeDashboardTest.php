<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Mpesa;
use App\Models\Summary;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Regression coverage for App\Http\Controllers\APIs\Dashboard\HomeAPIController::getDashboard.
 *
 * This previously used MySQL-only raw SQL (DAYNAME/DAYOFMONTH/YEAR/MONTH),
 * which 500'd on the production Postgres database — see App\Services\Sql\DatePartSql.
 */
final class HomeDashboardTest extends QueueTestCase
{
    // GET /api/dashboard reports a SACCO's weekly and monthly takings and now
    // sits behind View Transactions, like every other money screen in its group.
    // It was the only one any signed-in member could reach, so a driver could
    // read their SACCO's revenue. These actors hold the permission because the
    // endpoint is a money endpoint, not because the tests needed loosening.

    #[Test]
    public function the_weekly_view_groups_by_day_name(): void
    {
        $world = $this->makeWorld();
        Transaction::create([
            'vehicle_id' => $world['vehicle']->id,
            'amount' => 500,
            'trans_date' => now(),
        ]);
        Sanctum::actingAs($this->makeUser(['View Transactions'], $world['sacco']));

        $this->getJson('/api/auth/dashboard?year=0')
            ->assertOk()
            ->assertJsonStructure(['mpesa', 'cash', 'totals', 'transactions', 'xaxis']);
    }

    #[Test]
    public function the_monthly_view_groups_by_day_of_month(): void
    {
        $world = $this->makeWorld();
        Transaction::create([
            'vehicle_id' => $world['vehicle']->id,
            'amount' => 500,
            'trans_date' => now(),
        ]);
        Sanctum::actingAs($this->makeUser(['View Transactions'], $world['sacco']));

        $this->getJson('/api/auth/dashboard?year=1')->assertOk();
    }

    #[Test]
    public function the_yearly_view_groups_by_year_and_month(): void
    {
        $world = $this->makeWorld();
        Transaction::create([
            'vehicle_id' => $world['vehicle']->id,
            'amount' => 500,
            'trans_date' => now(),
        ]);
        Sanctum::actingAs($this->makeUser(['View Transactions'], $world['sacco']));

        $this->getJson('/api/auth/dashboard?year=4')->assertOk();
    }

    #[Test]
    public function today_is_today_and_does_not_move_when_the_period_changes(): void
    {
        // THE BUG. `mpesa`, `cash` and `totals` are the SELECTED PERIOD's
        // takings and always were — they use the week/month/3-month window the
        // buttons choose. Nothing in the payload said so, so the dashboard
        // labelled them "Collected today" and the tile changed every time
        // somebody pressed a different period button. On 29 Aug NICCO had taken
        // KES 724,858; the tile read 16,888,522.

        // The clock is pinned to a THURSDAY on purpose. The period this test
        // contrasts "today" against is the current week, and the older
        // transaction below is three days back — so on a Monday, Tuesday or
        // Wednesday that row lands in the PREVIOUS week, the period total comes
        // back as 1000 instead of 6000, and this fails for a reason that has
        // nothing to do with the behaviour it covers. It passed when it was
        // written on a Saturday and broke the next Monday.
        Carbon::setTestNow('2026-08-27 10:00:00');

        $world = $this->makeWorld();

        $this->transactionFor($world['vehicle'], 1000, now());
        $this->transactionFor($world['vehicle'], 5000, now()->subDays(3));

        Sanctum::actingAs($this->makeUser(['View Transactions'], $world['sacco']));

        $week = $this->getJson('/api/v1/auth/dashboard')->assertOk()->json();
        $month = $this->getJson('/api/v1/auth/dashboard?month=1')->assertOk()->json();

        // Today is the same number whichever period is selected...
        $this->assertSame(1000.0, (float) $week['today']['total']);
        $this->assertSame(1000.0, (float) $month['today']['total']);
        $this->assertSame(now()->toDateString(), $week['today']['date']);

        // ...and the period total is a DIFFERENT number, which is the point.
        $this->assertSame(6000.0, (float) $week['period']['total']);
        $this->assertNotSame(
            (float) $week['today']['total'],
            (float) $week['period']['total'],
            'today and the period must be distinguishable in the payload'
        );
    }

    #[Test]
    public function the_period_states_the_window_it_covers(): void
    {
        // So a tile can say WHICH window it is showing rather than the client
        // inferring it from the button it happened to press.
        //
        // The window is the NAIROBI week. This used to be asserted against
        // now() — the UTC week — which is the behaviour that was wrong: the
        // money is filed under Nairobi dates, and from 00:00 to 03:00 EAT the
        // UTC date is still yesterday. Late on a Sunday UTC that is a whole
        // week out. Pinned to exactly that hour so the two can never agree by
        // luck: 22:00 UTC Sunday 6 Sep is 01:00 EAT Monday 7 Sep.
        Carbon::setTestNow('2026-09-06 22:00:00');

        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser(['View Transactions'], $world['sacco']));

        $body = $this->getJson('/api/v1/auth/dashboard')->assertOk()->json();

        $nairobi = Carbon::now('Africa/Nairobi');
        $this->assertSame($nairobi->copy()->startOfWeek()->toDateString(), $body['period']['from']);
        $this->assertSame($nairobi->copy()->endOfWeek()->toDateString(), $body['period']['to']);
        $this->assertSame('2026-09-07', $body['period']['from'], 'Monday 7 Sep in Nairobi starts the week');
    }

    #[Test]
    public function the_existing_keys_are_untouched(): void
    {
        // Additive only — the dashboard renders against these today.
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser(['View Transactions'], $world['sacco']));

        $body = $this->getJson('/api/v1/auth/dashboard')->assertOk()->json();

        foreach (['mpesa', 'cash', 'totals', 'transactions', 'xaxis'] as $key) {
            $this->assertArrayHasKey($key, $body, $key.' is part of the existing contract');
        }
    }

    /**
     * A paid transaction on a vehicle, dated — and rolled into that day's
     * summary, as C2bPaymentRecorder::rollIntoSummary does for every fare it
     * attributes.
     *
     * The summary half is new. The dashboard's periods are read from the daily
     * rollup now (HomeAPIController explains why: 5.0 s → milliseconds on six
     * months), so a fixture that wrote only the transaction described a state
     * production never has — a fare with no summary — and the period would have
     * lost every past day of it. Today's summary is written too, deliberately:
     * the controller takes today from `transactions` and must NOT also count
     * today's summary, and a fixture without one could not catch it doing so.
     */
    private function transactionFor($vehicle, float $amount, $at): void
    {
        $mpesa = Mpesa::withoutGlobalScopes()->create([
            'TransID' => 'TX'.$this->nextSequence(),
            'TransAmount' => (string) $amount,
            'TransTime' => $at,
            'MSISDN' => '254712345678',
            'BusinessShortCode' => '7100466',
        ]);

        Transaction::withoutGlobalScopes()->create([
            'vehicle_id' => $vehicle->id,
            'mpesa_id' => $mpesa->id,
            'amount' => $amount,
            'trans_date' => $at,
        ]);

        $summary = Summary::withoutGlobalScopes()->firstOrNew(
            ['vehicle_id' => $vehicle->id, 'trans_date' => Carbon::parse($at)->toDateString()],
            ['mpesa_amount' => 0, 'cash_amount' => 0, 'mpesa_txn' => 0, 'cash_txn' => 0],
        );
        $summary->mpesa_amount = (float) $summary->mpesa_amount + $amount;
        $summary->mpesa_txn = (int) $summary->mpesa_txn + 1;
        $summary->save();
    }
}
