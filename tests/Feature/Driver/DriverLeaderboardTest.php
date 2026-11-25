<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Enums\UserType;
use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Crew see where their bus ranks in its SACCO, and nobody else's money.
 */
final class DriverLeaderboardTest extends QueueTestCase
{
    private const URL = '/api/v1/auth/driver/leaderboard';

    protected function setUp(): void
    {
        parent::setUp();
        // Thursday 20 Aug 2026, 15:00 EAT: this week runs Mon 17 - Sun 23.
        Carbon::setTestNow('2026-08-20 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function bus(Sacco $sacco): Vehicle
    {
        return $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
    }

    private function crewOn(Vehicle $vehicle): User
    {
        $driver = $this->makeUser([], $vehicle->sacco);
        $driver->forceFill(['type' => UserType::Driver])->save();
        VehicleUser::create([
            'user_id' => $driver->id, 'vehicle_id' => $vehicle->id, 'sacco_id' => $vehicle->sacco_id,
            'status' => true, 'start_date' => now(),
        ]);

        return $driver;
    }

    private function took(Vehicle $vehicle, float $mpesa, string $date, float $cash = 0): void
    {
        DB::table('summaries')->insert([
            'vehicle_id' => $vehicle->id, 'mpesa_amount' => $mpesa, 'cash_amount' => $cash,
            'mpesa_txn' => 1, 'cash_txn' => $cash > 0 ? 1 : 0, 'trans_date' => $date,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function board(User $as, array $query = []): array
    {
        Sanctum::actingAs($as);

        return $this->getJson(self::URL.($query ? '?'.http_build_query($query) : ''))->assertOk()->json();
    }

    #[Test]
    public function it_ranks_the_sacco_and_shows_only_the_callers_money(): void
    {
        $sacco = $this->makeSacco();
        [$a, $mine, $c] = [$this->bus($sacco), $this->bus($sacco), $this->bus($sacco)];
        $this->took($a, 5000, '2026-08-18');
        $this->took($mine, 2000, '2026-08-18', cash: 1000);
        $this->took($c, 8123, '2026-08-19');

        $body = $this->board($this->crewOn($mine));

        $this->assertSame('week', $body['period']);
        $this->assertSame([$c->plate, $a->plate, $mine->plate], array_column($body['vehicles'], 'plate'));
        $this->assertSame([1, 2, 3], array_column($body['vehicles'], 'rank'));
        $this->assertSame([false, false, true], array_column($body['vehicles'], 'is_you'));
        $this->assertSame(3, $body['you']['rank']);
        $this->assertEqualsWithDelta(3000.0, (float) $body['you']['collections'], 0.001, 'M-Pesa + cash, like the office');

        foreach ($body['vehicles'] as $row) {
            $this->assertSame(['rank', 'plate', 'fleet_no', 'is_you'], array_keys($row), 'no amounts for any bus in the list');
        }
        $raw = json_encode($body);
        $this->assertStringNotContainsString('8123', $raw);
        $this->assertStringNotContainsString('5000', $raw);
    }

    #[Test]
    public function the_default_is_this_week_and_other_periods_are_on_request(): void
    {
        $sacco = $this->makeSacco();
        [$steady, $mine] = [$this->bus($sacco), $this->bus($sacco)];
        $this->took($steady, 900, '2026-08-17');
        $this->took($mine, 500, '2026-08-20');
        $this->took($mine, 9000, '2026-08-14');  // last week, same month
        $driver = $this->crewOn($mine);

        $week = $this->board($driver);
        $this->assertSame('2026-08-17', $week['from']);
        $this->assertSame('2026-08-23', $week['to']);
        $this->assertSame(2, $week['you']['rank'], "last week's takings do not count this week");

        $this->assertSame(1, $this->board($driver, ['period' => 'month'])['you']['rank']);
        $this->assertSame(1, $this->board($driver, ['period' => 'day'])['you']['rank'], 'only the caller took money today');
    }

    #[Test]
    public function another_saccos_buses_never_appear(): void
    {
        $sacco = $this->makeSacco();
        $mine = $this->bus($sacco);
        $this->took($mine, 100, '2026-08-18');
        $stranger = $this->bus($this->makeSacco());
        $this->took($stranger, 99999, '2026-08-18');

        $body = $this->board($this->crewOn($mine));

        $this->assertSame([$mine->plate], array_column($body['vehicles'], 'plate'));
        $this->assertSame(1, $body['ranked']);
    }

    #[Test]
    public function one_sacco_is_one_fleet_across_brands(): void
    {
        $sacco = $this->makeSacco();
        $mine = $this->bus($sacco);
        $otherBrand = $this->bus($sacco);
        $otherBrand->forceFill(['brand' => 'elsewhere'])->save();
        $this->took($mine, 100, '2026-08-18');
        $this->took($otherBrand, 200, '2026-08-18');

        $plates = array_column($this->board($this->crewOn($mine))['vehicles'], 'plate');

        $this->assertSame([$otherBrand->plate, $mine->plate], $plates);
    }

    #[Test]
    public function a_bus_with_nothing_yet_is_unranked_but_still_told_where_it_stands(): void
    {
        $sacco = $this->makeSacco();
        $earner = $this->bus($sacco);
        $mine = $this->bus($sacco);
        $this->took($earner, 300, '2026-08-18');

        $body = $this->board($this->crewOn($mine));

        $this->assertNull($body['you']['rank']);
        $this->assertEqualsWithDelta(0.0, (float) $body['you']['collections'], 0.001);
        $this->assertSame([$earner->plate], array_column($body['vehicles'], 'plate'));
    }

    #[Test]
    public function the_list_is_capped_but_the_callers_rank_is_not(): void
    {
        $sacco = $this->makeSacco();
        foreach ([900, 800, 700] as $amount) {
            $this->took($this->bus($sacco), $amount, '2026-08-18');
        }
        $mine = $this->bus($sacco);
        $this->took($mine, 100, '2026-08-18');

        $body = $this->board($this->crewOn($mine), ['limit' => 2]);

        $this->assertCount(2, $body['vehicles']);
        $this->assertSame(4, $body['you']['rank']);
        $this->assertSame(4, $body['ranked']);
    }

    #[Test]
    public function no_assignment_no_leaderboard(): void
    {
        $driver = $this->makeUser([], $this->makeSacco());
        $driver->forceFill(['type' => UserType::Driver])->save();
        Sanctum::actingAs($driver);

        $this->getJson(self::URL)->assertForbidden();
    }

    #[Test]
    public function a_bad_period_is_refused(): void
    {
        $sacco = $this->makeSacco();
        Sanctum::actingAs($this->crewOn($this->bus($sacco)));

        $this->getJson(self::URL.'?period=year')->assertStatus(400);
    }
}
