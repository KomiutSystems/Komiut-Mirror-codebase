<?php

declare(strict_types=1);

namespace Tests\Feature\Sacco;

use App\Auth\Roles;
use App\Models\Summary;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * summaries/overview: the app's management screens -- the day, week and month
 * at a glance -- scoped exactly as the summaries list is.
 */
final class SummariesOverviewTest extends QueueTestCase
{
    private const URL = '/api/v1/auth/summaries/overview?date=2026-10-15';

    private function took(Vehicle $v, float $mpesa, float $cash, string $on): void
    {
        Summary::create([
            'vehicle_id' => $v->id, 'mpesa_amount' => $mpesa, 'cash_amount' => $cash,
            'mpesa_txn' => $mpesa > 0 ? 1 : 0, 'cash_txn' => $cash > 0 ? 1 : 0,
            'expense_fee_amount' => '0', 'trans_date' => $on,
        ]);
    }

    /** @return array{0: array, 1: Vehicle, 2: Vehicle} */
    private function fleet(): array
    {
        $world = $this->makeWorld();
        $a = $world['vehicle'];
        $a->forceFill(['plate' => 'KDA 001A'])->save();
        $b = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $b->forceFill(['plate' => 'KDB 002B'])->save();

        // Thursday 15 October 2026: week is Mon 12 - Sun 18, month is October.
        $this->took($a, 1000, 0, '2026-10-15');
        $this->took($a, 500, 0, '2026-10-13');
        $this->took($a, 300, 0, '2026-10-02');
        $this->took($a, 999, 0, '2026-09-30');
        $this->took($b, 0, 200, '2026-10-15');

        $other = $this->makeWorld();
        $this->took($other['vehicle'], 5000, 0, '2026-10-15');

        return [$world, $a->fresh(), $b->fresh()];
    }

    private function admin(array $world): User
    {
        $u = $this->makeUser(['View Summaries'], $world['sacco']);
        $u->forceFill(['sacco_id' => $world['sacco']->id])->save();

        return $u->fresh();
    }

    #[Test]
    public function a_sacco_sees_its_day_week_and_month_and_the_buses_ranked(): void
    {
        [$world, $a, $b] = $this->fleet();
        Sanctum::actingAs($this->admin($world));

        $res = $this->getJson(self::URL)->assertOk();

        $this->assertEquals(1200, $res->json('totals.day.collections'));
        $this->assertEquals(1000, $res->json('totals.day.mpesa_amount'));
        $this->assertEquals(200, $res->json('totals.day.cash_amount'));
        $this->assertSame('2026-10-12', $res->json('totals.week.from'));
        $this->assertSame('2026-10-18', $res->json('totals.week.to'));
        $this->assertEquals(1700, $res->json('totals.week.collections'));
        $this->assertEquals(2000, $res->json('totals.month.collections'), 'September is not October');

        $daily = collect($res->json('daily'));
        $this->assertCount(31, $daily);
        $this->assertEquals(1200, $daily->firstWhere('date', '2026-10-15')['collections']);
        $this->assertEquals(0, $daily->firstWhere('date', '2026-10-14')['collections']);

        $this->assertSame(['KDA 001A', 'KDB 002B'], array_column($res->json('vehicles'), 'plate'));
        $this->assertStringNotContainsString('5000', (string) json_encode($res->json('totals')), 'another SACCO\'s bus is not counted');
    }

    #[Test]
    public function the_ranked_period_can_be_the_week_or_the_month(): void
    {
        [$world] = $this->fleet();
        Sanctum::actingAs($this->admin($world));

        $this->assertEquals(1800, $this->getJson(self::URL.'&period=month')->assertOk()->json('vehicles.0.collections'));
        $this->assertEquals(1500, $this->getJson(self::URL.'&period=week')->assertOk()->json('vehicles.0.collections'));
        $this->getJson(self::URL.'&period=year')->assertStatus(400);
        $this->getJson('/api/v1/auth/summaries/overview?date=15-10-2026')->assertStatus(400);
    }

    #[Test]
    public function an_investor_sees_only_the_buses_they_own(): void
    {
        [$world, $a, $b] = $this->fleet();
        $investor = $this->makeUser(['View Summaries'], $world['sacco']);
        Role::findOrCreate(Roles::INVESTOR, 'web');
        $investor->assignRole(Roles::INVESTOR);
        VehicleUser::withoutGlobalScopes()->create([
            'user_id' => $investor->id, 'vehicle_id' => $b->id, 'sacco_id' => $b->sacco_id,
            'status' => true, 'start_date' => now()->subMonth(),
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($investor->fresh());
        $res = $this->getJson(self::URL)->assertOk();

        $this->assertEquals(200, $res->json('totals.day.collections'));
        $this->assertEquals(200, $res->json('totals.month.collections'));
        $this->assertSame(['KDB 002B'], array_column($res->json('vehicles'), 'plate'));
    }

    #[Test]
    public function staff_with_no_sacco_see_nothing_not_every_bus(): void
    {
        $this->fleet();
        Sanctum::actingAs($this->makeUser(['View Summaries']));

        $res = $this->getJson(self::URL)->assertOk();
        $this->assertEquals(0, $res->json('totals.month.collections'));
        $this->assertSame(0, $res->json('idle'), 'not every idle bus in the brand');
        $this->assertSame(0, $this->getJson('/api/v1/auth/summaries')->assertOk()->json('idle.count'));
    }

    #[Test]
    public function it_needs_view_summaries(): void
    {
        [$world] = $this->fleet();
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->getJson(self::URL)->assertForbidden();
    }
}
