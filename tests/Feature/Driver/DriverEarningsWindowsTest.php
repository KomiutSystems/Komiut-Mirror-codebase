<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Enums\UserType;
use App\Models\ExpenseFee;
use App\Models\Sacco;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpenseAndFee;
use App\Models\VehicleUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The earnings screen rolls one vehicle's takings up over four business-day
 * windows — today / week / month / all-time — in a single call. Earnings are
 * PER VEHICLE (drivers rotate), so every window is scoped to the vehicle on the
 * caller's open assignment, and each window is bounded by the 03:00-EAT business
 * day, not the calendar day.
 */
final class DriverEarningsWindowsTest extends QueueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A fixed, mid-month "now" so the window maths is deterministic: far
        // enough from the 1st that a fare 10 days back still lands in-month, and
        // clear of the 03:00 boundary. UTC noon == 15:00 EAT, business day Aug 20.
        Carbon::setTestNow('2026-08-20 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0: User, 1: Vehicle} */
    private function crewedDriver(?Sacco $sacco = null): array
    {
        $sacco ??= $this->makeSacco();
        $owner = $this->makeUser([], $sacco);
        $vehicle = $this->makeVehicle($sacco, $owner, $this->makeSeat());

        $driver = $this->makeUser([], $sacco);
        $driver->forceFill(['type' => UserType::Driver, 'firstname' => 'Grace', 'lastname' => 'Wanjiru'])->save();

        VehicleUser::create([
            'user_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'sacco_id' => $sacco->id,
            'status' => true,
            'start_date' => now(),
        ]);

        return [$driver, $vehicle];
    }

    private function payment(Vehicle $vehicle, float $amount, string $method, ?string $at = null): Transaction
    {
        return Transaction::create([
            'vehicle_id' => $vehicle->id,
            'amount' => $amount,
            'trans_date' => $at ? Carbon::parse($at) : now(),
            'mpesa_id' => $method === 'mpesa' ? 1 : 0,
            'cash_id' => $method === 'cash' ? 1 : 0,
        ]);
    }

    #[Test]
    public function each_window_is_bounded_by_the_business_day(): void
    {
        [$driver, $vehicle] = $this->crewedDriver();

        // Today: 200 cash + 300 mpesa.
        $this->payment($vehicle, 200, 'cash');
        $this->payment($vehicle, 300, 'mpesa');
        // 10 days ago: inside the month, outside the last 7 days.
        $this->payment($vehicle, 1000, 'cash', '2026-08-10 12:00:00');
        // 40 days ago: before the 1st, so only all-time sees it.
        $this->payment($vehicle, 500, 'cash', '2026-07-11 12:00:00');

        Sanctum::actingAs($driver);

        $body = $this->getJson('/api/v1/auth/driver/earnings')->assertOk()->json();

        // Today and this week both see only today's money.
        $this->assertSame(200.0, (float) $body['today']['cash']);
        $this->assertSame(300.0, (float) $body['today']['mpesa']);
        $this->assertSame(500.0, (float) $body['today']['net']);

        $this->assertSame(200.0, (float) $body['week']['cash']);
        $this->assertSame(300.0, (float) $body['week']['mpesa']);
        $this->assertSame(500.0, (float) $body['week']['net']);

        // The 10-day-old fare shows up in the month (and all-time) but NOT today
        // or this week.
        $this->assertSame(1200.0, (float) $body['month']['cash']);
        $this->assertSame(300.0, (float) $body['month']['mpesa']);
        $this->assertSame(1500.0, (float) $body['month']['net']);

        // All-time additionally picks up the 40-day-old fare.
        $this->assertSame(1700.0, (float) $body['all_time']['cash']);
        $this->assertSame(300.0, (float) $body['all_time']['mpesa']);
        $this->assertSame(2000.0, (float) $body['all_time']['net']);
    }

    #[Test]
    public function a_fare_ten_days_ago_is_absent_from_today_and_week(): void
    {
        [$driver, $vehicle] = $this->crewedDriver();
        $this->payment($vehicle, 1000, 'cash', '2026-08-10 12:00:00');

        Sanctum::actingAs($driver);
        $body = $this->getJson('/api/v1/auth/driver/earnings')->assertOk()->json();

        $this->assertSame(0.0, (float) $body['today']['net'], 'A 10-day-old fare must not appear in today.');
        $this->assertSame(0.0, (float) $body['week']['net'], 'A 10-day-old fare must not appear in this week.');
        $this->assertSame(1000.0, (float) $body['month']['net']);
        $this->assertSame(1000.0, (float) $body['all_time']['net']);
    }

    #[Test]
    public function net_is_takings_minus_expenses_within_the_window(): void
    {
        [$driver, $vehicle] = $this->crewedDriver();
        $this->payment($vehicle, 1000, 'cash');

        $fuel = ExpenseFee::create(['name' => 'Fuel', 'status' => true]);
        VehicleExpenseAndFee::create([
            'vehicle_id' => $vehicle->id,
            'expense_fee_id' => $fuel->id,
            'amount' => 250,
            'trans_date' => now(),
            'status' => true,
        ]);

        Sanctum::actingAs($driver);
        $body = $this->getJson('/api/v1/auth/driver/earnings')->assertOk()->json();

        $this->assertSame(1000.0, (float) $body['today']['cash']);
        // What the driver actually goes home with.
        $this->assertSame(750.0, (float) $body['today']['net']);
        $this->assertSame(750.0, (float) $body['all_time']['net']);
    }

    #[Test]
    public function a_driver_never_sees_another_vehicles_money_in_any_window(): void
    {
        $sacco = $this->makeSacco();
        [$driver, $mine] = $this->crewedDriver($sacco);
        [, $theirs] = $this->crewedDriver($sacco);

        $this->payment($mine, 100, 'cash');
        $this->payment($theirs, 999, 'cash', '2026-08-10 12:00:00');

        Sanctum::actingAs($driver);
        $body = $this->getJson('/api/v1/auth/driver/earnings')->assertOk()->json();

        $this->assertSame(100.0, (float) $body['today']['net']);
        $this->assertSame(100.0, (float) $body['all_time']['net'], 'All-time must still be this vehicle only.');
    }

    #[Test]
    public function the_response_names_the_vehicle_and_the_assigned_driver(): void
    {
        [$driver, $vehicle] = $this->crewedDriver();

        Sanctum::actingAs($driver);
        $response = $this->getJson('/api/v1/auth/driver/earnings')->assertOk();

        $response
            ->assertJsonPath('vehicle.id', $vehicle->id)
            ->assertJsonPath('vehicle.plate', $vehicle->plate)
            ->assertJsonPath('driver.id', $driver->id)
            ->assertJsonPath('driver.name', 'Grace Wanjiru')
            ->assertJsonStructure([
                'vehicle' => ['id', 'plate'],
                'driver' => ['id', 'name'],
                'today' => ['cash', 'mpesa', 'net', 'trips', 'drivers'],
                'week' => ['cash', 'mpesa', 'net', 'trips'],
                'month' => ['cash', 'mpesa', 'net', 'trips'],
                'all_time' => ['cash', 'mpesa', 'net', 'trips'],
                // The old response is still a subset — the current app keeps working.
                'takings' => ['earnings', 'mpesa', 'cash', 'trips', 'net'],
            ]);

        // The caller is listed among today's drivers on this vehicle.
        $ids = collect($response->json('today.drivers'))->pluck('id')->all();
        $this->assertContains($driver->id, $ids, 'Today\'s crew must include the assigned driver.');
    }

    #[Test]
    public function trips_are_counted_per_window_and_exclude_cancelled_queues(): void
    {
        $world = $this->makeWorld();

        $driver = $this->makeUser([], $world['sacco']);
        $driver->forceFill(['type' => UserType::Driver])->save();
        VehicleUser::create([
            'user_id' => $driver->id,
            'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id,
            'status' => true,
            'start_date' => now(),
        ]);

        $completed = $this->makeQueueStatus('Completed', 'Completed');
        $cancelled = $this->makeQueueStatus('Cancelled', 'Cancelled');

        // One real trip today, one real trip 10 days ago, one cancelled today.
        $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $completed, $world['owner'], 'QN-TODAY');
        $tenDaysAgo = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $completed, $world['owner'], 'QN-OLD');
        DB::table('queues')->where('id', $tenDaysAgo->id)
            ->update(['created_at' => '2026-08-10 12:00:00']);
        $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $cancelled, $world['owner'], 'QN-CANCELLED');

        Sanctum::actingAs($driver);
        $body = $this->getJson('/api/v1/auth/driver/earnings')->assertOk()->json();

        $this->assertSame(1, $body['today']['trips'], 'Cancelled queues must not count.');
        $this->assertSame(1, $body['week']['trips']);
        $this->assertSame(2, $body['month']['trips'], 'The 10-day-old trip is in-month.');
        $this->assertSame(2, $body['all_time']['trips']);
    }
}
