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
    #[Test]
    public function the_weekly_view_groups_by_day_name(): void
    {
        $world = $this->makeWorld();
        Transaction::create([
            'vehicle_id' => $world['vehicle']->id,
            'amount' => 500,
            'trans_date' => now(),
        ]);
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

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
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

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
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->getJson('/api/auth/dashboard?year=4')->assertOk();
    }
}
