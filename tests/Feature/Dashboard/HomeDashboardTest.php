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
}
