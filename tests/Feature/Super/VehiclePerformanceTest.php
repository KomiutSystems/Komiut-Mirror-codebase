<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Enums\UserType;
use App\Models\Sacco;
use App\Models\Summary;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * super/vehicles/performance: buses ranked by takings per working day, one-off
 * deposits set aside, any SACCO excludable, read from the daily summaries.
 */
final class VehiclePerformanceTest extends QueueTestCase
{
    private const URL = '/api/v1/super/vehicles/performance';

    protected function setUp(): void
    {
        parent::setUp();
        // 12:00 EAT on 2026-10-30: the default window is 1-30 October.
        $this->travelTo(Carbon::parse('2026-10-30 09:00:00', 'UTC'));
    }

    private function superAdmin(): User
    {
        $user = $this->makeUser();
        $user->forceFill(['type' => UserType::Superadmin])->save();
        Permission::findOrCreate('View Platform Notifications', 'web');
        $user->givePermissionTo('View Platform Notifications');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function bus(?Sacco $sacco = null): Vehicle
    {
        $sacco ??= $this->makeSacco();

        return $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
    }

    /** One day's summary row, as app:generate-vehicle-summaries writes it. */
    private function day(Vehicle $bus, string $date, float $mpesa, float $cash = 0, ?int $mpesaTxn = null, int $cashTxn = 0): void
    {
        Summary::withoutGlobalScopes()->create([
            'vehicle_id' => $bus->id, 'trans_date' => $date,
            'mpesa_amount' => $mpesa, 'cash_amount' => $cash,
            'mpesa_txn' => $mpesaTxn ?? ($mpesa > 0 ? 10 : 0), 'cash_txn' => $cashTxn,
        ]);
    }

    /** $amount on each of $days days, ending on 2026-10-30. */
    private function worked(Vehicle $bus, int $days, float $amount): void
    {
        for ($d = 0; $d < $days; $d++) {
            $this->day($bus, Carbon::parse('2026-10-30')->subDays($d)->toDateString(), $amount);
        }
    }

    /** @return list<int> */
    private function ranked(string $query = ''): array
    {
        return array_column($this->getJson(self::URL.$query)->assertOk()->json('data'), 'vehicle_id');
    }

    #[Test]
    public function buses_are_ranked_by_takings_per_working_day_and_a_one_off_deposit_is_set_aside(): void
    {
        $allMonth = $this->bus();
        $this->worked($allMonth, 20, 600);      // 12,000: the bigger total
        $lateJoiner = $this->bus();
        $this->worked($lateJoiner, 8, 1000);    // 8,000: the better bus every day it ran
        $oneOff = $this->bus();
        $this->day($oneOff, '2026-10-12', 56000, 0, 1);
        Sanctum::actingAs($this->superAdmin());

        $res = $this->getJson(self::URL)->assertOk();

        $this->assertSame([$lateJoiner->id, $allMonth->id], array_column($res->json('data'), 'vehicle_id'));
        $this->assertSame([1, 2], array_column($res->json('data'), 'rank'));
        $this->assertEqualsWithDelta(1000, $res->json('data.0.kes_per_day'), 0.001);
        $this->assertSame(8, $res->json('data.0.active_days'));
        $this->assertSame('2026-10-30', $res->json('data.0.last_active_date'));
        $this->assertSame(7, $res->json('meta.min_active_days'));      // a quarter of 30 days
        $this->assertSame(1, $res->json('meta.below_min_days'));       // the deposit, counted not hidden
        $this->assertSame(3, $res->json('meta.totals.buses'));
        $this->assertEqualsWithDelta(76000, $res->json('meta.totals.kes'), 0.001);
        $this->assertSame(2, $res->json('total'));

        $this->assertSame([$allMonth->id, $lateJoiner->id], $this->ranked('?sort=kes'));
        $this->assertSame([$oneOff->id, $lateJoiner->id, $allMonth->id], $this->ranked('?min_active_days=1'));
    }

    #[Test]
    public function any_sacco_can_be_left_out_or_singled_out(): void
    {
        $nicco = $this->makeSacco();
        $big = $this->bus($nicco);
        $this->worked($big, 10, 5000);
        $small = $this->bus();
        $this->worked($small, 10, 900);
        Sanctum::actingAs($this->superAdmin());

        $this->assertSame([$big->id, $small->id], $this->ranked());

        $res = $this->getJson(self::URL.'?exclude_sacco_id[]='.$nicco->id)->assertOk();
        $this->assertSame([$small->id], array_column($res->json('data'), 'vehicle_id'));
        $this->assertEqualsWithDelta(9000, $res->json('meta.totals.kes'), 0.001);

        $this->assertSame([$big->id], $this->ranked('?sacco_id='.$nicco->id));
    }

    #[Test]
    public function only_days_inside_the_window_count_and_a_day_with_no_payments_is_not_a_working_day(): void
    {
        $bus = $this->bus();
        $this->day($bus, '2026-10-09', 999);     // before the window
        $this->day($bus, '2026-10-10', 100);
        $this->day($bus, '2026-10-11', 0, 0, 0); // a row with nothing on it
        $this->day($bus, '2026-10-12', 300);
        $this->day($bus, '2026-10-13', 999);     // after it
        Sanctum::actingAs($this->superAdmin());

        $res = $this->getJson(self::URL.'?from=2026-10-10&to=2026-10-12')->assertOk();

        $this->assertSame(3, $res->json('meta.days'));
        $this->assertSame(1, $res->json('meta.min_active_days'));
        $this->assertEqualsWithDelta(400, $res->json('data.0.kes'), 0.001);
        $this->assertSame(2, $res->json('data.0.active_days'));
        $this->assertEqualsWithDelta(200, $res->json('data.0.kes_per_day'), 0.001);
        $this->assertSame('2026-10-12', $res->json('data.0.last_active_date'));
    }

    #[Test]
    public function mpesa_and_cash_are_counted_separately(): void
    {
        $bus = $this->bus();
        $this->day($bus, '2026-10-30', 300, 200, 3, 2);
        Sanctum::actingAs($this->superAdmin());

        $row = $this->getJson(self::URL.'?min_active_days=1')->assertOk()->json('data.0');

        $this->assertEqualsWithDelta(500, $row['kes'], 0.001);
        $this->assertEqualsWithDelta(300, $row['mpesa_kes'], 0.001);
        $this->assertEqualsWithDelta(200, $row['cash_kes'], 0.001);
        $this->assertSame(5, $row['payments']);
    }

    #[Test]
    public function ranks_carry_on_across_pages(): void
    {
        foreach ([3000, 2000, 1000] as $perDay) {
            $this->worked($this->bus(), 10, $perDay);
        }
        Sanctum::actingAs($this->superAdmin());

        $res = $this->getJson(self::URL.'?per_page=2&page=2')->assertOk();

        $this->assertSame([3], array_column($res->json('data'), 'rank'));
        $this->assertSame(3, $res->json('total'));
        $this->assertSame(2, $res->json('last_page'));
    }

    #[Test]
    public function a_window_longer_than_ninety_days_or_running_backwards_is_refused(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->getJson(self::URL.'?from=2026-07-01&to=2026-10-30')->assertStatus(422);
        $this->getJson(self::URL.'?from=2026-10-30&to=2026-10-01')->assertStatus(422);
        $this->getJson(self::URL.'?from=2026-08-02&to=2026-10-30')->assertOk();   // exactly 90
    }

    #[Test]
    public function it_is_for_super_admins_only(): void
    {
        $sacco = $this->makeSacco();
        Sanctum::actingAs($this->makeUser(['View Platform Notifications'], $sacco));

        $this->getJson(self::URL)->assertForbidden();
    }

    #[Test]
    public function it_reads_the_daily_summaries_never_the_transactions_table(): void
    {
        $this->worked($this->bus(), 10, 1000);
        Sanctum::actingAs($this->superAdmin());

        $reads = 0;
        DB::listen(function ($q) use (&$reads): void {
            if (str_contains($q->sql, '"transactions"')) {
                $reads++;
            }
        });
        $this->getJson(self::URL.'?from=2026-08-02&to=2026-10-30')->assertOk();

        // 6-12s on production for a 30-90 day window (2026-10-30). Summaries: 19ms.
        $this->assertSame(0, $reads);
    }
}
