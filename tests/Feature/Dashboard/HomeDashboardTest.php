<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Transaction;
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
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser(['View Transactions'], $world['sacco']));

        $body = $this->getJson('/api/v1/auth/dashboard')->assertOk()->json();

        $this->assertSame(now()->startOfWeek()->toDateString(), $body['period']['from']);
        $this->assertSame(now()->endOfWeek()->toDateString(), $body['period']['to']);
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

    /** A paid transaction on a vehicle, dated. */
    private function transactionFor($vehicle, float $amount, $at): void
    {
        $mpesa = \App\Models\Mpesa::withoutGlobalScopes()->create([
            'TransID' => 'TX'.$this->nextSequence(),
            'TransAmount' => (string) $amount,
            'TransTime' => $at,
            'MSISDN' => '254712345678',
            'BusinessShortCode' => '7100466',
        ]);

        \App\Models\Transaction::withoutGlobalScopes()->create([
            'vehicle_id' => $vehicle->id,
            'mpesa_id' => $mpesa->id,
            'amount' => $amount,
            'trans_date' => $at,
        ]);
    }
}
